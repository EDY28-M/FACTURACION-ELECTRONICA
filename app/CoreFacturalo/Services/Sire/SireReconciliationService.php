<?php

namespace App\CoreFacturalo\Services\Sire;

use App\CoreFacturalo\WS\Gre\Exceptions\GreApiException;
use App\CoreFacturalo\WS\Gre\Exceptions\GreAuthException;
use App\CoreFacturalo\WS\Gre\Exceptions\GreConfigurationException;
use App\CoreFacturalo\WS\Gre\Exceptions\GreConnectionException;
use App\CoreFacturalo\WS\Gre\Exceptions\GreException;
use App\CoreFacturalo\WS\Sire\SireClient;
use App\Models\Tenant\Company;
use App\Models\Tenant\SireRequest;
use Carbon\Carbon;
use RuntimeException;

/**
 * READ-ONLY reconciliation of the tenant's sales against SUNAT's RVIE propuesta (SIRE API).
 *
 * Flow (no background queue, the user drives it):
 *  1. request($period): asks SUNAT to export the propuesta and stores the ticket.
 *  2. refresh($request): polls the ticket once; when SUNAT finished it downloads the ZIP, parses the TXT,
 *     reconciles it with the system sales and stores only the summary JSON. The ZIP itself is never stored.
 *
 * Nothing is uploaded, accepted or replaced in SUNAT.
 */
class SireReconciliationService
{
    /** Open requests older than this are considered abandoned and expire (status failed). */
    public const EXPIRY_HOURS = 24;

    /** A request still without ticket after this many minutes means the export call never completed. */
    public const NO_TICKET_MINUTES = 5;

    /** @var SireClient|null */
    private $client;

    /** @var SireRequestStore */
    private $store;

    /** @var callable fn(int $year, int $month): array{rows: array, excluded: array} */
    private $systemRows;

    public function __construct(?SireClient $client = null, ?SireRequestStore $store = null, ?callable $systemRows = null)
    {
        $this->client = $client;
        $this->store = $store ?: new EloquentSireRequestStore();
        $this->systemRows = $systemRows ?: function (int $year, int $month) {
            return (new RvieReplacementBuilder())->reconciliationRows($year, $month);
        };
    }

    /**
     * @throws GreConfigurationException when the company has no usable credentials
     */
    private function client(): SireClient
    {
        if (!$this->client) {
            $company = Company::active();
            if (!$company) {
                throw new GreConfigurationException('No hay una empresa configurada.');
            }
            $this->client = new SireClient(SireClient::settingsForCompany($company));
        }

        return $this->client;
    }

    /**
     * @return array{request: SireRequest, message: string}
     * @throws \InvalidArgumentException invalid period
     * @throws GreException              SUNAT/credentials problem (use friendlyError())
     * @throws RuntimeException          the comparison could not be completed
     */
    public function request(string $period): array
    {
        SireSupport::validatePeriod($period);

        $client = $this->client();

        // Atomic: expire stale records, reuse a live one, or create the placeholder. The SUNAT call happens
        // outside the lock; the placeholder (no ticket yet) blocks a concurrent double request meanwhile.
        $created = $this->store->atomic($period, function () use ($period) {
            foreach ($this->store->openRecords($period) as $open) {
                if ($this->expireIfStale($open)) {
                    continue;
                }

                return ['existing' => $open];
            }

            return ['new' => $this->store->save(new SireRequest([
                'book' => SireClient::BOOK_RVIE,
                'period' => $period,
                'status' => SireRequest::STATUS_REQUESTED,
                'status_detail' => 'Enviando solicitud a SUNAT.',
                'requested_at' => Carbon::now(),
            ]))];
        });

        if (isset($created['existing'])) {
            return ['request' => $created['existing'], 'message' => 'Ya existe una solicitud en curso para este periodo. Actualice su estado.'];
        }

        $record = $created['new'];

        try {
            $ticket = $client->requestProposalExport($period);
        } catch (GreApiException $e) {
            if (!$this->hasCode($e, '1070')) {
                $this->fail($record, self::friendlyError($e));
                throw $e;
            }

            // [unverified] 1070 "no se ha encontrado informacion de comprobantes" = SUNAT's propuesta is empty
            // (manual v32, 5.18 evidence 2). The SUNAT side is treated as empty instead of failing.
            try {
                $record = $this->reconcile($record, [], ['header' => true, 'delimiter' => '', 'skipped' => 0], 'SUNAT informa que la propuesta del periodo está vacía.');
            } catch (\Throwable $t) {
                $this->fail($record, 'No se pudo comparar con tus ventas: '.$t->getMessage());
                throw new RuntimeException('No se pudo completar la comparación con tus ventas.', 0, $t);
            }

            return ['request' => $record, 'message' => 'La propuesta de SUNAT está vacía para el periodo; se comparó contra tus ventas.'];
        } catch (GreException $e) {
            $this->fail($record, self::friendlyError($e));
            throw $e;
        } catch (\Throwable $t) {
            $this->fail($record, 'No se pudo enviar la solicitud a SUNAT.');
            throw $t;
        }

        $record->ticket = $ticket;
        $record->status_detail = 'Solicitud enviada a SUNAT.';
        $this->store->save($record);

        return ['request' => $record, 'message' => 'Solicitud enviada (ticket '.$ticket.'). Use "Actualizar estado" para consultar el avance.'];
    }

    /**
     * Polls the ticket once and, when ready, downloads and reconciles.
     *
     * @return array{request: SireRequest, message: string}
     * @throws GreException SUNAT/credentials problem (use friendlyError()); retryable ones leave the record open
     */
    public function refresh(SireRequest $record): array
    {
        if (!$record->isOpen()) {
            return ['request' => $record, 'message' => 'La solicitud ya finalizó.'];
        }

        if ($this->expireIfStale($record)) {
            return ['request' => $record, 'message' => $record->status_detail];
        }

        if (!$record->ticket) {
            return ['request' => $record, 'message' => 'La solicitud aún se está enviando a SUNAT.'];
        }

        $client = $this->client();

        try {
            $status = $client->ticketStatus($record->ticket, $record->period);
        } catch (GreException $e) {
            if (self::isRetryable($e)) {
                throw $e; // transient: the record stays open, the user can refresh again
            }
            $this->fail($record, self::friendlyError($e));

            return ['request' => $record, 'message' => $record->status_detail];
        }

        if ($status['state'] === 'failed') {
            $this->fail($record, 'SUNAT procesó el ticket con errores'.($status['description'] ? ' ('.$status['description'].')' : '').'.');

            return ['request' => $record, 'message' => $record->status_detail];
        }

        if ($status['state'] === 'processing') {
            $record->status = SireRequest::STATUS_PROCESSING;
            $record->status_detail = $status['description'] ?: 'SUNAT aún está procesando la solicitud.';
            $this->store->save($record);

            return ['request' => $record, 'message' => 'SUNAT aún está procesando la propuesta. Intente nuevamente en unos minutos.'];
        }

        // Ready: pick the ZIP (SUNAT may list several files; the propuesta ZIP is the first one).
        $file = null;
        foreach ($status['files'] as $candidate) {
            if (preg_match('/\.zip$/i', $candidate['name'])) {
                $file = $candidate;
                break;
            }
        }
        $file = $file ?: ($status['files'][0] ?? null);

        if (!$file) {
            $this->fail($record, 'SUNAT terminó el ticket pero no informó un archivo para descargar.');

            return ['request' => $record, 'message' => $record->status_detail];
        }

        $record->status = SireRequest::STATUS_READY;
        $record->file_name = $file['name'];
        $record->status_detail = 'Archivo listo para descargar.';
        $this->store->save($record);

        try {
            $parsed = self::downloadAndParse($client, $file, $record->ticket, $record->period, $status['process'] ?: SireClient::PROCESS_EXPORT_PROPOSAL);
            $record = $this->reconcile($record, $parsed['rows'], $parsed, null);
        } catch (GreException $e) {
            $this->fail($record, self::friendlyError($e));

            return ['request' => $record, 'message' => $record->status_detail];
        } catch (RuntimeException $e) {
            $this->fail($record, $e->getMessage());

            return ['request' => $record, 'message' => $e->getMessage()];
        } catch (\Throwable $t) {
            $this->fail($record, 'No se pudo completar la comparación con tus ventas.');

            return ['request' => $record, 'message' => $record->status_detail];
        }

        return ['request' => $record, 'message' => 'Propuesta descargada y comparada con tus ventas.'];
    }

    /** Marks the record failed (terminal). */
    private function fail(SireRequest $record, string $detail): void
    {
        $record->status = SireRequest::STATUS_FAILED;
        $record->status_detail = mb_substr($detail, 0, 500);
        $record->finished_at = Carbon::now();
        $this->store->save($record);
    }

    /**
     * Open requests older than EXPIRY_HOURS, or without a ticket for NO_TICKET_MINUTES, are abandoned: they are
     * closed as failed so the user can always request again.
     */
    private function expireIfStale(SireRequest $record): bool
    {
        $requestedAt = $record->requested_at ?: $record->created_at;
        if (!$requestedAt) {
            $requestedAt = Carbon::now()->subYears(1);
        }

        $expired = $requestedAt->lt(Carbon::now()->subHours(self::EXPIRY_HOURS))
            || (!$record->ticket && $requestedAt->lt(Carbon::now()->subMinutes(self::NO_TICKET_MINUTES)));

        if ($expired) {
            $this->fail($record, 'La solicitud venció sin completarse. Solicite la propuesta nuevamente.');
        }

        return $expired;
    }

    private static function isRetryable(GreException $e): bool
    {
        if ($e instanceof GreConnectionException) {
            return true;
        }
        $status = (int) $e->getHttpStatus();

        return $status === 429 || $status >= 500;
    }

    /**
     * Downloads the ZIP (service 5.17) and parses the propuesta TXT. The ZIP is kept in memory only.
     *
     * @return array{rows: array, skipped: int, header: bool, delimiter: string}
     * @throws GreException     download problem
     * @throws RuntimeException the ZIP is not a valid propuesta
     */
    public static function downloadAndParse(SireClient $client, array $file, string $ticket, string $period, string $process = SireClient::PROCESS_EXPORT_PROPOSAL): array
    {
        return SirePropuestaParser::parseZip($client->downloadReport($file, $ticket, $period, $process));
    }

    /**
     * Pure part of the reconciliation: SUNAT rows + system rows -> summary stored in sire_requests.summary.
     */
    public static function buildSummary(string $period, array $sunatRows, array $meta, array $system): array
    {
        $summary = SireReconciler::reconcile($sunatRows, $system['rows'], $system['excluded']);
        $summary['period'] = $period;
        $summary['generated_at'] = Carbon::now()->toDateTimeString();
        $summary['parser'] = [
            'rows' => count($sunatRows), 'skipped' => $meta['skipped'] ?? 0,
            'header' => (bool) ($meta['header'] ?? true), 'delimiter' => $meta['delimiter'] ?? '',
        ];
        $summary['warnings'] = [];
        if (!($meta['header'] ?? true)) {
            // [unverified] layout of the exported propuesta (the manual does not document it).
            $summary['warnings'][] = 'El archivo de SUNAT no trae encabezado reconocible; se usó el orden de columnas del Anexo 3 (no verificado).';
        }
        if (($meta['skipped'] ?? 0) > 0) {
            $summary['warnings'][] = sprintf('%d línea(s) del archivo de SUNAT no se pudieron interpretar.', $meta['skipped']);
        }

        return $summary;
    }

    /**
     * Compares the SUNAT rows with the system sales and stores the summary.
     */
    private function reconcile(SireRequest $record, array $sunatRows, array $meta, ?string $detail): SireRequest
    {
        $system = ($this->systemRows)((int) substr($record->period, 0, 4), (int) substr($record->period, 4, 2));

        $record->status = SireRequest::STATUS_RECONCILED;
        $record->status_detail = $detail ?: 'Comparación completada.';
        $record->finished_at = Carbon::now();
        $record->summary = self::buildSummary($record->period, $sunatRows, $meta, $system);

        return $this->store->save($record);
    }

    private function hasCode(GreException $e, string $code): bool
    {
        if ((string) $e->getSunatCode() === $code) {
            return true;
        }
        foreach ($e->getErrors() as $error) {
            if ((string) ($error['codError'] ?? '') === $code) {
                return true;
            }
        }

        return false;
    }

    /**
     * Spanish message for any SUNAT/SIRE failure. Never includes credentials.
     */
    public static function friendlyError(GreException $e): string
    {
        if ($e instanceof GreConfigurationException) {
            return $e->getMessage();
        }
        if ($e instanceof GreConnectionException) {
            return 'No se pudo conectar con SUNAT. Verifique su conexión e intente nuevamente en unos minutos.';
        }
        if ($e instanceof GreAuthException) {
            return 'SUNAT rechazó las credenciales de la API (client_id, client_secret, usuario o clave SOL). '
                .'Verifique los datos de la empresa y que las credenciales tengan habilitada la opción "MIGE RCE y RVIE - SIRE".';
        }

        $known = [
            '1006' => 'El periodo no tiene el formato AAAAMM.',
            '1007' => 'SUNAT no admite periodos futuros.',
            '1052' => 'El número de ticket no tiene un formato válido.',
            '1070' => 'SUNAT no encontró comprobantes para el periodo seleccionado.',
            '2244' => 'El archivo solicitado ya no existe en SUNAT. Genere una nueva solicitud.',
        ];
        foreach ($e->getErrors() as $error) {
            $code = (string) ($error['codError'] ?? '');
            if (isset($known[$code])) {
                return $known[$code];
            }
        }

        $status = (int) $e->getHttpStatus();
        if ($status === 429) {
            return 'SUNAT recibió demasiadas solicitudes. Espere unos minutos e intente nuevamente.';
        }
        if ($status >= 500) {
            return 'El servicio SIRE de SUNAT no está disponible en este momento (HTTP '.$status.'). Intente más tarde.';
        }
        if ($status === 422 && $e->getErrors()) {
            $first = $e->getErrors()[0];

            return 'SUNAT rechazó la solicitud: '.trim(($first['codError'] ?? '').' '.($first['desError'] ?? '')).'.';
        }

        return 'SUNAT respondió con un error'.($status ? ' (HTTP '.$status.')' : '').'. '.($e->getSunatMessage() ?: 'Intente nuevamente.');
    }
}
