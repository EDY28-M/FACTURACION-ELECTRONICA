<?php

namespace App\CoreFacturalo\WS\Sire;

use App\CoreFacturalo\WS\Gre\Exceptions\GreApiException;
use App\CoreFacturalo\WS\Gre\Exceptions\GreConfigurationException;
use App\CoreFacturalo\WS\Gre\GreClient;
use App\Models\Tenant\Company;
use GuzzleHttp\ClientInterface;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * Read-only client for the SUNAT SIRE Ventas web API (RVIE), used to download the "propuesta".
 *
 * Source: "Manual de servicios web API - SIRE Ventas" v32.
 *  - Token (5.1): POST {token_url}/clientessol/{client_id}/oauth2/token/ with grant_type=password,
 *    scope=https://api-sire.sunat.gob.pe, client_id, client_secret, username ("{RUC}{USUARIO}"), password (clave SOL).
 *  - Export proposal (5.18): GET rvie/propuesta/web/propuesta/{perTributario}/exportapropuesta?codTipoArchivo=0 -> numTicket.
 *  - Ticket status (5.16): GET rvierce/gestionprocesosmasivos/web/masivo/consultaestadotickets.
 *  - Download file (5.17): GET rvierce/gestionprocesosmasivos/web/masivo/archivoreporte -> binary ZIP.
 *
 * This client never uploads, accepts or replaces anything: only the three read services above are used.
 *
 * Reuses the OAuth/cache/401-retry/typed-exception machinery of GreClient. The token cache key embeds the
 * scope and a hash of client secret + password, so SIRE and GRE tokens never collide.
 */
class SireClient extends GreClient
{
    /** Book code of the RVIE (manual v32, services 5.2/5.16/5.17). */
    const BOOK_RVIE = '140000';

    /** "Codigo de origen de envio": 2 = servicio web (manual v32, 5.16). */
    const ORIGIN_WEB_SERVICE = '2';

    /** Anexo I "Indicador de carga masiva" 10 = "Generar archivo exportar propuesta". */
    const PROCESS_EXPORT_PROPOSAL = '10';

    /** Anexo III "Codigo de estado de envio" (manual v32, section 7.3). */
    const STATE_LOADED = '01';
    const STATE_VALIDATING = '02';
    const STATE_PROCESSED_WITH_ERRORS = '03';
    const STATE_PROCESSED_OK = '04';
    const STATE_IN_PROCESS = '05';
    const STATE_FINISHED = '06';

    /** Status/export calls are quick; only the file download keeps the long (settings) timeout. */
    const SHORT_TIMEOUTS = ['connect_timeout' => 10, 'timeout' => 20];

    /** Max pages of consultaestadotickets walked to find a ticket. */
    const MAX_STATUS_PAGES = 10;

    const STATUS_PER_PAGE = 20;

    protected $apiLabel = 'SIRE';

    protected $cachePrefix = 'sire';

    public function __construct(array $settings, ?ClientInterface $http = null, ?CacheRepository $cache = null)
    {
        $settings += [
            'scope' => 'https://api-sire.sunat.gob.pe',
            'timeout' => 60,
        ];

        parent::__construct($settings, $http, $cache);
    }

    /**
     * Builds the client settings for a company. SIRE credentials (client id/secret from
     * "Menu SOL > Empresas > Credenciales de API SUNAT", with the "MIGE RCE y RVIE - SIRE" URI ticked) are
     * preferred; when empty the GRE API credentials are used, since SUNAT lets one application cover both URIs.
     * The SOL user/password are the company's soap_username/soap_password.
     *
     * @throws GreConfigurationException
     */
    public static function settingsForCompany(Company $company)
    {
        $clientId = trim((string) $company->sire_client_id);
        $secret = null;
        if ($clientId !== '' && $company->hasSireClientSecret()) {
            $secret = $company->getSireClientSecret();
        } elseif ($clientId === '' && !$company->hasSireClientSecret()) {
            // Fallback: same application as GRE.
            $clientId = trim((string) $company->gre_client_id);
            $secret = $company->hasGreClientSecret() ? $company->getGreClientSecret() : null;
        }

        $username = (string) $company->soap_username;
        // The token endpoint wants "{RUC}{USUARIO}"; some setups already store the RUC inside the user.
        if ($username !== '' && strpos($username, (string) $company->number) !== 0) {
            $username = $company->number.$username;
        }

        $missing = [];
        if ($clientId === '') {
            $missing[] = 'client_id';
        }
        if (!$secret) {
            $missing[] = 'client_secret';
        }
        if ($username === '' || !$company->soap_password) {
            $missing[] = 'usuario y clave SOL';
        }
        if ($missing) {
            throw new GreConfigurationException(
                'Faltan credenciales para consultar SIRE ('.implode(', ', $missing).'). '
                .'Configúrelas en Empresa > Configuración > Credenciales API SUNAT (SIRE) o GRE.'
            );
        }

        return [
            'ruc' => $company->number,
            'token_url' => config('sire.token_url'),
            'api_url' => config('sire.api_url'),
            'scope' => config('sire.scope'),
            'connect_timeout' => config('sire.connect_timeout'),
            'timeout' => config('sire.timeout'),
            'client_id' => $clientId,
            'client_secret' => $secret,
            'username' => $username,
            'password' => $company->soap_password,
        ];
    }

    /**
     * Asks SUNAT to generate the RVIE propuesta TXT for a period (service 5.18).
     * Returns the ticket number ("AAAA99999999").
     *
     * @param string $period AAAAMM
     */
    public function requestProposalExport($period)
    {
        $this->assertPeriod($period);

        $url = rtrim($this->settings['api_url'], '/').'/rvie/propuesta/web/propuesta/'.$period.'/exportapropuesta';
        $body = $this->authorizedRequest('GET', $url, ['query' => ['codTipoArchivo' => 0]] + self::SHORT_TIMEOUTS);

        if (empty($body['numTicket'])) {
            throw new GreApiException('SUNAT no devolvió un número de ticket para la propuesta.', 200, null, null);
        }

        return (string) $body['numTicket'];
    }

    /**
     * Queries one ticket (service 5.16) and normalizes the answer.
     *
     * @return array{state:string,code:string,description:string,files:array,period:?string,process:?string,found:bool}
     *               state: processing|ready|failed
     */
    public function ticketStatus($ticket, $period)
    {
        $this->assertPeriod($period);

        $url = rtrim($this->settings['api_url'], '/').'/rvierce/gestionprocesosmasivos/web/masivo/consultaestadotickets';
        // The ticket is searched page by page (numTicket is sent too, but the manual does not promise filtering).
        $record = null;
        for ($page = 1; $page <= self::MAX_STATUS_PAGES && $record === null; $page++) {
            $body = $this->authorizedRequest('GET', $url, ['query' => [
                'perIni' => $period,
                'perFin' => $period,
                'page' => $page,
                'perPage' => self::STATUS_PER_PAGE,
                'numTicket' => $ticket,
                'codLibro' => self::BOOK_RVIE,
                'codOrigenEnvio' => self::ORIGIN_WEB_SERVICE,
            ]] + self::SHORT_TIMEOUTS);

            $rows = is_array($body['registros'] ?? null) ? $body['registros'] : [];
            foreach ($rows as $candidate) {
                if (is_array($candidate) && (string) ($candidate['numTicket'] ?? '') === (string) $ticket) {
                    $record = $candidate;
                    break;
                }
            }

            $total = (int) ($body['paginacion']['totalRegistros'] ?? 0);
            if ($record !== null || !$rows || $page * self::STATUS_PER_PAGE >= $total) {
                break;
            }
        }

        if ($record === null) {
            // [unverified] A ticket that is not listed yet is treated as still being queued.
            return [
                'found' => false, 'state' => 'processing', 'code' => '', 'description' => 'Ticket aún no listado por SUNAT.',
                'files' => [], 'period' => $period, 'process' => null,
            ];
        }

        $code = (string) ($record['codEstadoProceso'] ?? ($record['detalleTicket']['codEstadoEnvio'] ?? ''));
        $description = (string) ($record['desEstadoProceso'] ?? ($record['detalleTicket']['desEstadoEnvio'] ?? ''));

        $files = [];
        foreach (($record['archivoReporte'] ?? []) as $file) {
            // The manual spells the type field "codTipoAchivoReporte" in 5.16; accept both spellings.
            $name = $file['nomArchivoReporte'] ?? null;
            if ($name) {
                $files[] = [
                    'type' => $file['codTipoArchivoReporte'] ?? ($file['codTipoAchivoReporte'] ?? null),
                    'name' => (string) $name,
                    'content' => $file['nomArchivoContenido'] ?? null,
                ];
            }
        }
        if (!$files && !empty($record['detalleTicket']['nomArchivoReporte'])) {
            $files[] = ['type' => null, 'name' => (string) $record['detalleTicket']['nomArchivoReporte'], 'content' => null];
        }

        if ($code === self::STATE_PROCESSED_WITH_ERRORS) {
            $state = 'failed';
        } elseif ($code === self::STATE_FINISHED || ($code === self::STATE_PROCESSED_OK && $files)) {
            $state = 'ready';
        } else {
            $state = 'processing';
        }

        return [
            'found' => true,
            'state' => $state,
            'code' => $code,
            'description' => $description,
            'files' => $files,
            'period' => (string) ($record['perTributario'] ?? $period),
            'process' => isset($record['codProceso']) ? (string) $record['codProceso'] : self::PROCESS_EXPORT_PROPOSAL,
        ];
    }

    /**
     * Downloads the generated report (service 5.17): a ZIP with the propuesta TXT. Returns the binary content.
     *
     * @param array  $file   one entry of ticketStatus()['files']
     * @param string $ticket numTicket
     */
    public function downloadReport(array $file, $ticket, $period, $process = self::PROCESS_EXPORT_PROPOSAL)
    {
        $this->assertPeriod($period);

        $query = [
            'nomArchivoReporte' => $file['name'],
            'perTributario' => $period,
            'codProceso' => $process,
            'numTicket' => $ticket,
            'codLibro' => self::BOOK_RVIE,
        ];
        // Manual 5.17: send the type as returned by 5.16; when 5.16 returned null it says to send the same (null).
        // An empty string is sent in that case [unverified].
        $query['codTipoArchivoReporte'] = isset($file['type']) ? (string) $file['type'] : '';

        $url = rtrim($this->settings['api_url'], '/').'/rvierce/gestionprocesosmasivos/web/masivo/archivoreporte';
        $content = (string) $this->authorizedRaw('GET', $url, ['query' => $query])->getBody();

        if (strncmp($content, "PK", 2) !== 0) {
            throw new GreApiException('SUNAT no devolvió un archivo ZIP válido para el ticket '.$ticket.'.', 200, null, null);
        }

        return $content;
    }

    private function assertPeriod($period)
    {
        if (!preg_match('/^\d{6}$/', (string) $period)) {
            throw new GreConfigurationException('El periodo debe tener el formato AAAAMM.');
        }
    }
}
