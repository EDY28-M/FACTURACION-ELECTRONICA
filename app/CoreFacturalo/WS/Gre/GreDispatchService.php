<?php

namespace App\CoreFacturalo\WS\Gre;

use App\CoreFacturalo\Facturalo;
use App\CoreFacturalo\Helpers\QrCode\QrCodeGenerate;
use App\CoreFacturalo\Helpers\Storage\StorageDocument;
use App\CoreFacturalo\WS\Gre\Exceptions\GreConfigurationException;
use App\CoreFacturalo\WS\Gre\Exceptions\GreException;
use App\CoreFacturalo\WS\Reader\DomCdrReader;
use App\CoreFacturalo\WS\Zip\ZipFileDecompress;
use App\CoreFacturalo\WS\Zip\ZipFly;
use App\Models\Tenant\Company;
use App\Models\Tenant\Dispatch;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrates sending a GRE (type 09) to SUNAT through the REST API and
 * processing the ticket status / CDR.
 *
 * Every public method returns an array:
 *  - success (bool): the operation completed without errors
 *  - message (string): Spanish message ready for the UI
 *  - state_type_id (string): state of the guide after the operation
 *  - sent (bool), skipped (bool)
 */
class GreDispatchService
{
    use StorageDocument;

    const REGISTERED = '01';
    const SENT = '03';
    const ACCEPTED = '05';
    const REJECTED = '09';

    /** @var GreClient|null */
    private $client;

    public function __construct(GreClient $client = null)
    {
        $this->client = $client;
    }

    /**
     * Builds the client settings for a company. Production uses the SOL user
     * of the company plus the GRE API client id/secret. Demo mode only works
     * when the "test" block of config/gre.php is fully configured.
     *
     * @throws GreConfigurationException
     */
    public static function settingsForCompany(Company $company, $soapTypeId = null)
    {
        $soapTypeId = $soapTypeId ?: $company->soap_type_id;
        $base = [
            'ruc' => $company->number,
            'scope' => config('gre.scope'),
            'connect_timeout' => config('gre.connect_timeout'),
            'timeout' => config('gre.timeout'),
        ];

        if ($soapTypeId === '01') {
            $test = config('gre.test');

            return $base + [
                'token_url' => $test['token_url'] ?? null,
                'api_url' => $test['api_url'] ?? null,
                'client_id' => $test['client_id'] ?? null,
                'client_secret' => $test['client_secret'] ?? null,
                'username' => $test['username'] ?? null,
                'password' => $test['password'] ?? null,
            ];
        }

        $username = (string) $company->soap_username;
        if ($username !== '' && strpos($username, (string) $company->number) !== 0) {
            $username = $company->number.$username;
        }

        return $base + [
            'token_url' => config('gre.production.token_url'),
            'api_url' => config('gre.production.api_url'),
            'client_id' => $company->gre_client_id,
            'client_secret' => $company->getGreClientSecret(),
            'username' => $username,
            'password' => $company->soap_password,
        ];
    }

    /**
     * Returns a Spanish message when sending must be skipped, null otherwise.
     */
    public static function skipReason(Company $company, $soapTypeId = null)
    {
        if (($soapTypeId ?: $company->soap_type_id) !== '01') {
            return null;
        }

        $test = config('gre.test');
        foreach (['token_url', 'api_url', 'client_id', 'client_secret', 'username', 'password'] as $key) {
            if (empty($test[$key])) {
                return 'La empresa está en modo demo y SUNAT no ofrece un ambiente beta para la API de GRE. '
                    .'La guía fue generada y firmada pero no se envió. Configure producción o las variables '
                    .'GRE_TEST_* en el archivo .env (ver config/gre.php) para un entorno de pruebas de terceros.';
            }
        }

        return null;
    }

    /**
     * Sends (or re-sends) a guide and performs one immediate status query.
     * A failure never rolls back the guide: it stays "Registrado". This method
     * never throws: any error becomes a failure result.
     */
    public function send(Dispatch $dispatch)
    {
        $claimed = false;

        try {
            $company = Company::active();

            if ($dispatch->series[0] !== 'T') {
                return $this->result(false, 'Solo las guías con serie electrónica (T) se envían a SUNAT.', $dispatch);
            }

            if ($dispatch->state_type_id === self::REJECTED) {
                return $this->result(false, 'La guía fue rechazada por SUNAT; emite una nueva guía con otro número.', $dispatch);
            }

            if ($skip = self::skipReason($company, $dispatch->soap_type_id)) {
                return $dispatch->state_type_id === self::REGISTERED
                    ? $this->result(true, $skip, $dispatch, ['skipped' => true])
                    : $this->result(false, 'La guía ya fue enviada a SUNAT. Use "Consultar estado".', $dispatch);
            }

            // Atomic claim: only one request can move the guide out of "Registrado".
            // A claim without ticket older than 2 minutes is considered stale (crashed request).
            $claimed = Dispatch::where('id', $dispatch->id)
                ->where(function ($query) {
                    $query->where('state_type_id', self::REGISTERED)
                        ->orWhere(function ($stale) {
                            $stale->where('state_type_id', self::SENT)
                                ->whereNull('ticket')
                                ->where('updated_at', '<', now()->subMinutes(2));
                        });
                })
                ->update(['state_type_id' => self::SENT]) === 1;

            if (!$claimed) {
                return $this->result(false, 'La guía ya fue enviada a SUNAT o se está enviando. Use "Consultar estado".', $dispatch->fresh());
            }

            $client = $this->client ?: new GreClient(self::settingsForCompany($company, $dispatch->soap_type_id));

            $xml = $this->getStorage($dispatch->filename, 'signed');
            if (!$xml) {
                $this->release($dispatch);

                return $this->result(false, 'No se encontró el XML firmado de la guía.', $dispatch->fresh());
            }

            $zip = (new ZipFly())->compress($dispatch->filename.'.xml', $xml);
            $sent = $client->send($dispatch->filename, $zip);
        } catch (GreException $e) {
            if ($claimed) {
                $this->release($dispatch);
            }

            return $this->failure($dispatch->fresh(), $e, 'No se pudo enviar la guía a SUNAT. Puede reintentarlo.');
        } catch (\Throwable $e) {
            Log::error('GRE: unexpected error sending dispatch '.$dispatch->id.': '.$e->getMessage(), ['exception' => $e]);
            if ($claimed) {
                $this->release($dispatch);
            }

            return $this->result(false, 'No se pudo enviar la guía a SUNAT por un error inesperado. La guía quedó registrada; puede reintentarlo.', $dispatch->fresh());
        }

        // SUNAT already holds the guide: persist the ticket on its own so a local failure is traceable.
        try {
            $dispatch->update([
                'ticket' => $sent['ticket'],
                'state_type_id' => self::SENT,
                'soap_shipping_response' => [
                    'sent' => true,
                    'code' => '98',
                    'description' => 'Guía recibida por SUNAT. Ticket '.$sent['ticket'].'.',
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error("GRE: SUNAT accepted dispatch {$dispatch->id} with ticket {$sent['ticket']} but the local write failed: ".$e->getMessage(), ['exception' => $e]);

            return $this->result(false, "SUNAT recibió la guía (ticket {$sent['ticket']}) pero no se pudo guardar el ticket localmente. Anote el ticket y contacte a soporte.", $dispatch, ['sent' => true]);
        }

        try {
            $status = $this->status($dispatch->fresh(), $client);
        } catch (\Throwable $e) {
            Log::error("GRE: status query failed for dispatch {$dispatch->id}: ".$e->getMessage(), ['exception' => $e]);
            $status = $this->result(false, 'La guía fue enviada (ticket '.$sent['ticket'].') pero no se pudo consultar el estado. Use "Consultar estado".', $dispatch->fresh());
        }
        $status['sent'] = true;

        return $status;
    }

    /**
     * Releases a claim taken by send() when nothing was sent.
     */
    private function release(Dispatch $dispatch)
    {
        Dispatch::where('id', $dispatch->id)
            ->where('state_type_id', self::SENT)
            ->whereNull('ticket')
            ->update(['state_type_id' => self::REGISTERED]);
    }

    /**
     * Queries the ticket of a sent guide and updates its state/CDR/QR.
     */
    public function status(Dispatch $dispatch, GreClient $client = null)
    {
        if (!$dispatch->ticket) {
            return $this->result(false, 'La guía no tiene ticket de SUNAT. Envíela primero.', $dispatch);
        }

        if ($dispatch->state_type_id !== self::SENT) {
            return $this->result(true, 'La guía ya tiene un estado final: '.$dispatch->state_type->description.'.', $dispatch);
        }

        try {
            $client = $client ?: $this->client ?: new GreClient(self::settingsForCompany(Company::active(), $dispatch->soap_type_id));
            $result = $client->status($dispatch->ticket);
        } catch (GreException $e) {
            return $this->failure($dispatch, $e, 'No se pudo consultar el estado en SUNAT. Intente nuevamente.', false);
        }

        if ($result->isProcessing()) {
            return $this->result(true, 'SUNAT aún está procesando la guía (ticket '.$dispatch->ticket.').', $dispatch, ['sent' => true]);
        }

        $cdr = $this->storeCdr($dispatch, $result);

        if ($result->isAccepted()) {
            $dispatch->update([
                'state_type_id' => self::ACCEPTED,
                'has_cdr' => $cdr !== null,
                'soap_shipping_response' => [
                    'sent' => true,
                    'code' => $cdr['code'] ?? '0',
                    'description' => $cdr['description'] ?? 'La Guía de Remisión ha sido aceptada',
                ],
            ]);

            $this->storeQr($dispatch->fresh(), $cdr['qr_url'] ?? null);

            return $this->result(true, 'La guía fue aceptada por SUNAT.', $dispatch->fresh(), ['sent' => true]);
        }

        $description = $cdr['description'] ?? $result->errorMessage ?? 'Guía rechazada por SUNAT.';
        $code = $cdr['code'] ?? $result->errorCode ?? '99';

        $dispatch->update([
            'state_type_id' => self::REJECTED,
            'has_cdr' => $cdr !== null,
            'soap_shipping_response' => [
                'sent' => true,
                'code' => $code,
                'description' => $description,
            ],
        ]);

        return $this->result(false, "SUNAT rechazó la guía: {$code} - {$description}", $dispatch->fresh(), ['sent' => true]);
    }

    /**
     * Saves the CDR zip and reads its code/description/QR url (best effort).
     *
     * @return array|null
     */
    private function storeCdr(Dispatch $dispatch, GreStatusResult $result)
    {
        if (!$result->cdrZip) {
            return null;
        }

        $this->uploadStorage($dispatch->filename, $result->cdrZip, 'cdr');

        $info = ['code' => null, 'description' => null, 'qr_url' => null];

        try {
            $files = (new ZipFileDecompress())->decompress($result->cdrZip, function ($filename) {
                return strtolower(pathinfo($filename, PATHINFO_EXTENSION)) === 'xml';
            });
            $xml = $files[0]['content'] ?? '';

            if ($xml !== '') {
                $info['qr_url'] = $this->extractQrUrl($xml);

                try {
                    $response = (new DomCdrReader())->getCdrResponse($xml);
                    $info['code'] = $response->getCode();
                    $info['description'] = $response->getDescription();
                } catch (\Throwable $e) {
                    Log::warning('GRE CDR: could not read ApplicationResponse: '.$e->getMessage());
                }
            }
        } catch (\Throwable $e) {
            Log::warning('GRE CDR: could not read zip: '.$e->getMessage());
        }

        return $info;
    }

    /**
     * Looks for an https URL in the CDR (SUNAT places the QR/validation link
     * there). Unverified location: DocumentReference/DocumentDescription is
     * checked first, then any text node.
     */
    public function extractQrUrl($xml)
    {
        $doc = new \DOMDocument();
        if (!@$doc->loadXML($xml)) {
            return null;
        }
        $xpath = new \DOMXPath($doc);

        $queries = [
            '//*[local-name()="DocumentReference"]/*[local-name()="DocumentDescription"]',
            '//text()',
        ];

        foreach ($queries as $index => $query) {
            foreach ($xpath->query($query) as $node) {
                if (!preg_match('#https://[^\s<>"\']+#i', $node->nodeValue, $matches)) {
                    continue;
                }
                // The generic text fallback only trusts SUNAT hosts.
                $host = strtolower((string) parse_url($matches[0], PHP_URL_HOST));
                if ($index === 0 || $host === 'sunat.gob.pe' || substr($host, -strlen('.sunat.gob.pe')) === '.sunat.gob.pe') {
                    return $matches[0];
                }
            }
        }

        return null;
    }

    /**
     * Stores the QR (from the SUNAT URL, or a fallback text) and regenerates the PDF.
     */
    private function storeQr(Dispatch $dispatch, $qrUrl)
    {
        $text = $qrUrl ?: implode('|', [
            Company::active()->number,
            $dispatch->document_type_id,
            $dispatch->series,
            $dispatch->number,
            $dispatch->date_of_issue->format('Y-m-d'),
        ]);

        $dispatch->update([
            'sunat_qr_url' => $qrUrl,
            'qr' => (new QrCodeGenerate())->displayPNGBase64($text, 200),
        ]);

        try {
            $facturalo = new Facturalo();
            $facturalo->setDocument($dispatch->fresh());
            $facturalo->setType('dispatch');
            $facturalo->createPdf($dispatch->fresh(), 'dispatch', 'a4');
        } catch (\Throwable $e) {
            Log::warning('GRE: could not regenerate PDF with QR: '.$e->getMessage());
        }
    }

    private function failure(Dispatch $dispatch, GreException $e, $prefix, $persist = true)
    {
        Log::warning('GRE: '.get_class($e).' '.$e->getDetailedMessage());

        if ($persist) {
            $dispatch->update([
                'soap_shipping_response' => [
                    'sent' => false,
                    'code' => $e->getSunatCode() ?: ($e->getHttpStatus() ?: 'ERROR'),
                    'description' => $e->getDetailedMessage(),
                ],
            ]);
        }

        return $this->result(false, $prefix.' '.$e->getDetailedMessage(), $dispatch, [
            'errors' => $e->getErrors(),
            'sunat_code' => $e->getSunatCode(),
        ]);
    }

    private function result($success, $message, Dispatch $dispatch, array $extra = [])
    {
        return $extra + [
            'success' => $success,
            'message' => $message,
            'state_type_id' => $dispatch->state_type_id,
            'sent' => false,
            'skipped' => false,
        ];
    }
}
