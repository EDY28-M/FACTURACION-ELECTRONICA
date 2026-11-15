<?php

namespace App\CoreFacturalo\Services\Cpe;

use App\CoreFacturalo\WS\Cpe\CpeValidationClient;
use App\CoreFacturalo\WS\Gre\Exceptions\GreApiException;
use App\CoreFacturalo\WS\Gre\Exceptions\GreConfigurationException;
use App\CoreFacturalo\WS\Gre\Exceptions\GreException;
use App\Models\Tenant\Company;
use App\Models\Tenant\Purchase;
use Carbon\Carbon;
use InvalidArgumentException;

/**
 * Validates purchase documents against SUNAT's "Consulta Integrada de Validez de Comprobantes de Pago"
 * and stores the answer on the purchase (sunat_validation_*).
 */
class PurchaseValidationService
{
    const STATUS_NO_EXISTE = 'no_existe';
    const STATUS_ACEPTADO = 'aceptado';
    const STATUS_ANULADO = 'anulado';
    const STATUS_AUTORIZADO = 'autorizado';
    const STATUS_NO_AUTORIZADO = 'no_autorizado';
    const STATUS_NO_APLICA = 'no_aplica';
    const STATUS_ERROR = 'error';

    /** Status codes that deserve a warning when a purchase is declared in the RCE. */
    const PROBLEM_STATUSES = [self::STATUS_NO_EXISTE, self::STATUS_ANULADO, self::STATUS_NO_AUTORIZADO];

    /** estadoCp (manual v2.0): 0 NO EXISTE, 1 ACEPTADO, 2 ANULADO, 3 AUTORIZADO, 4 NO AUTORIZADO. */
    const CP_STATES = [
        0 => self::STATUS_NO_EXISTE,
        1 => self::STATUS_ACEPTADO,
        2 => self::STATUS_ANULADO,
        3 => self::STATUS_AUTORIZADO,
        4 => self::STATUS_NO_AUTORIZADO,
    ];

    const RUC_STATES = [
        '00' => 'ACTIVO',
        '01' => 'BAJA PROVISIONAL',
        '02' => 'BAJA PROV. POR OFICIO',
        '03' => 'SUSPENSIÓN TEMPORAL',
        '10' => 'BAJA DEFINITIVA',
        '11' => 'BAJA DE OFICIO',
        '22' => 'INHABILITADO-VENT.UNICA',
    ];

    const RUC_CONDITIONS = [
        '00' => 'HABIDO',
        '09' => 'PENDIENTE',
        '11' => 'POR VERIFICAR',
        '12' => 'NO HABIDO',
        '20' => 'NO HALLADO',
    ];

    const LABELS = [
        self::STATUS_NO_EXISTE => 'No existe',
        self::STATUS_ACEPTADO => 'Aceptado',
        self::STATUS_ANULADO => 'Anulado',
        self::STATUS_AUTORIZADO => 'Autorizado',
        self::STATUS_NO_AUTORIZADO => 'No autorizado',
        self::STATUS_NO_APLICA => 'No aplica',
        self::STATUS_ERROR => 'Error',
    ];

    /** codComp accepted by the API. */
    const SUPPORTED_TYPES = ['01', '03', '04', '07', '08', 'R1', 'R7'];

    /** Max documents validated per bulk call (also bounded by BULK_TIME_BUDGET) (the UI calls again until nothing remains). */
    const BULK_LIMIT = 25;

    /** Seconds after which a bulk call stops and returns its cursor (keeps the HTTP request short). */
    const BULK_TIME_BUDGET = 20;

    const RUC_IDENTITY_TYPE = '6';

    const VOIDED_STATE = '11';

    /** @var CpeValidationClient|null */
    private $client;

    public function __construct(?CpeValidationClient $client = null)
    {
        $this->client = $client;
    }

    /**
     * Builds the API request from a normalized document, or explains why it does not apply.
     *
     * Expected keys: document_type_id, series, number, date_of_issue (Y-m-d), total,
     * supplier[identity_document_type_id, number].
     *
     * monto is sent only for electronic documents (the API ignores/rejects it for printed ones). Also sent as the
     * stored total regardless of currency. [unverified] whether SUNAT expects the amount in the document currency
     * for foreign-currency invoices. The series
     * heuristic: electronic series start with a letter (F001, B001, E001...), printed (imprenta) ones are
     * numeric (0001). [unverified] against edge cases such as printed series that start with a letter.
     *
     * @return array ['payload' => array] or ['skip' => string]
     */
    public static function buildRequest(array $doc)
    {
        $type = strtoupper(trim((string) ($doc['document_type_id'] ?? '')));
        if (!in_array($type, self::SUPPORTED_TYPES, true)) {
            return ['skip' => 'El tipo de comprobante '.($type === '' ? '(vacío)' : $type).' no se puede validar en SUNAT.'];
        }

        $supplier = $doc['supplier'] ?? [];
        $ruc = trim((string) ($supplier['number'] ?? ''));
        if ((string) ($supplier['identity_document_type_id'] ?? '') !== self::RUC_IDENTITY_TYPE || !preg_match('/^\d{11}$/', $ruc)) {
            return ['skip' => 'El proveedor no tiene RUC; SUNAT solo valida comprobantes de emisores con RUC.'];
        }

        $series = strtoupper(trim((string) ($doc['series'] ?? '')));
        if (strlen($series) !== 4) {
            return ['skip' => 'La serie "'.$series.'" no tiene 4 caracteres.'];
        }

        $number = trim((string) ($doc['number'] ?? ''));
        if (!preg_match('/^\d{1,8}$/', $number) || (int) $number < 1) {
            return ['skip' => 'El número "'.$number.'" no es válido.'];
        }

        try {
            $date = Carbon::parse($doc['date_of_issue'])->format('d/m/Y');
        } catch (\Throwable $e) {
            return ['skip' => 'La fecha de emisión no es válida.'];
        }

        $payload = [
            'numRuc' => $ruc,
            'codComp' => $type,
            'numeroSerie' => $series,
            'numero' => (int) $number,
            'fechaEmision' => $date,
        ];

        if (preg_match('/^[A-Z]/', $series)) {
            $payload['monto'] = round((float) ($doc['total'] ?? 0), 2);
        }

        return ['payload' => $payload];
    }

    /**
     * Maps the "data" node of the API answer to a status and its stored detail.
     *
     * @return array ['status' => string, 'detail' => array]
     */
    public static function mapResponse(array $data)
    {
        $cp = $data['estadoCp'] ?? null;
        $ruc = isset($data['estadoRuc']) ? (string) $data['estadoRuc'] : null;
        $cond = isset($data['condDomiRuc']) ? (string) $data['condDomiRuc'] : null;
        $observations = array_values(array_filter(array_map('strval', (array) ($data['Observaciones'] ?? $data['observaciones'] ?? []))));

        $detail = [
            'estadoCp' => $cp === null ? null : (string) $cp,
            'estadoRuc' => $ruc,
            'estadoRucLabel' => $ruc !== null ? (self::RUC_STATES[$ruc] ?? null) : null,
            'condDomiRuc' => $cond,
            'condDomiRucLabel' => $cond !== null ? (self::RUC_CONDITIONS[$cond] ?? null) : null,
            'observaciones' => $observations,
            'message' => null,
        ];

        if ($cp === null || !is_numeric($cp) || !isset(self::CP_STATES[(int) $cp])) {
            $detail['message'] = 'SUNAT devolvió un estado de comprobante no reconocido ('.($cp === null ? 'vacío' : $cp).').';

            return ['status' => self::STATUS_ERROR, 'detail' => $detail];
        }

        return ['status' => self::CP_STATES[(int) $cp], 'detail' => $detail];
    }

    /**
     * Fingerprint of the fields SUNAT validates. When it changes after an edit, the stored result is stale.
     */
    public static function fingerprint($purchase)
    {
        $supplier = $purchase->supplier;
        $date = $purchase->date_of_issue;

        return json_encode([
            strtoupper(trim((string) $purchase->document_type_id)),
            strtoupper(trim((string) $purchase->series)),
            ltrim(trim((string) $purchase->number), '0'),
            $date ? Carbon::parse($date)->format('Y-m-d') : null,
            round((float) $purchase->total, 2),
            (string) $purchase->currency_type_id,
            (string) $purchase->supplier_id,
            (string) ($supplier->number ?? ''),
        ]);
    }

    /** Clears the stored validation result (written only here, never through mass assignment). */
    public static function resetValidation($purchase)
    {
        $purchase->forceFill([
            'sunat_validation_status' => null,
            'sunat_validation_detail' => null,
            'sunat_validated_at' => null,
        ]);
    }

    /** Converts an Eloquent purchase (or any object exposing the same attributes) to the array consumed by buildRequest(). */
    public static function normalize($purchase)
    {
        $supplier = $purchase->supplier;

        return [
            'document_type_id' => $purchase->document_type_id,
            'series' => $purchase->series,
            'number' => $purchase->number,
            'date_of_issue' => $purchase->date_of_issue ? $purchase->date_of_issue->format('Y-m-d') : null,
            'total' => $purchase->total,
            'supplier' => [
                'identity_document_type_id' => $supplier->identity_document_type_id ?? '',
                'number' => $supplier->number ?? '',
            ],
        ];
    }

    /**
     * Validates one purchase and stores the result.
     *
     * @return array ['status', 'label', 'detail', 'validated_at']
     * @throws GreException configuration, authentication, connection or HTTP errors (nothing is stored)
     */
    public function validatePurchase($purchase)
    {
        if ((string) ($purchase->state_type_id ?? '') === self::VOIDED_STATE) {
            return $this->store($purchase, self::STATUS_NO_APLICA, ['message' => 'La compra está anulada en el sistema; no se valida en SUNAT.']);
        }

        $built = self::buildRequest(self::normalize($purchase));

        if (isset($built['skip'])) {
            return $this->store($purchase, self::STATUS_NO_APLICA, ['message' => $built['skip']]);
        }

        $client = $this->client();

        try {
            $mapped = self::mapResponse($client->validate($built['payload']));
        } catch (GreApiException $e) {
            // success=false for this document (HTTP 200): record it and let the caller continue.
            if ($e->getHttpStatus() === 200) {
                return $this->store($purchase, self::STATUS_ERROR, ['message' => $e->getMessage()]);
            }
            throw $e;
        }

        return $this->store($purchase, $mapped['status'], $mapped['detail']);
    }

    /**
     * Validates up to BULK_LIMIT purchases of a period, in id order, sequentially.
     *
     * @param string   $period      YYYYMM
     * @param bool     $onlyPending only purchases never validated or that ended in error
     * @param int|null $afterId     cursor: process only ids greater than this one
     * @return array processed, by_status, remaining, next_after_id, stopped, message
     * @throws GreException configuration/authentication/connection errors before any progress is possible
     */
    public function validatePeriod($period, $onlyPending = true, $afterId = null, $limit = self::BULK_LIMIT)
    {
        if (!is_string($period) || !preg_match('/^\d{4}(0[1-9]|1[0-2])$/', $period)) {
            throw new InvalidArgumentException('El periodo debe tener el formato AAAAMM.');
        }

        $this->client();
        $query = $this->periodQuery($period, $onlyPending);
        $cursor = (int) $afterId;

        $rows = (clone $query)->where('id', '>', $cursor)->orderBy('id')->limit(max(1, (int) $limit))->get();
        try {
            $batch = $this->validateBatch($rows, $cursor);
        } catch (BulkValidationInterrupted $e) {
            $partial = $e->getPartial();
            $partial['remaining'] = (clone $query)->where('id', '>', $partial['next_after_id'])->count();
            throw new BulkValidationInterrupted($e->getCause(), $partial);
        }

        return [
            'processed' => $batch['processed'],
            'by_status' => $batch['by_status'],
            'remaining' => (clone $query)->where('id', '>', $batch['last_id'])->count(),
            'next_after_id' => $batch['last_id'],
            'stopped' => $batch['stopped'],
            'message' => $batch['message'],
        ];
    }

    /**
     * Validates the given purchases sequentially. Stops at the first HTTP 429 (SUNAT rate limit) and reports it;
     * any other SUNAT/connection/credential failure propagates.
     *
     * @param iterable $rows   purchases ordered by id
     * @param int      $lastId id reported when nothing was processed
     * @return array processed, by_status, last_id, stopped, message
     */
    public function validateBatch($rows, $lastId = 0)
    {
        $byStatus = [];
        $processed = 0;
        $stopped = false;
        $message = null;
        $startedAt = microtime(true);

        foreach ($rows as $purchase) {
            if ($processed > 0 && (microtime(true) - $startedAt) >= $this->timeBudget()) {
                break; // time budget used: the caller continues from last_id
            }

            try {
                $result = $this->validatePurchase($purchase);
            } catch (GreException $e) {
                if ($e instanceof GreApiException && $e->getHttpStatus() === 429) {
                    $stopped = true;
                    $message = 'SUNAT recibió demasiadas solicitudes. Se detuvo la validación; espere unos minutos y continúe.';
                    break;
                }
                if ($processed === 0) {
                    throw $e;
                }
                throw new BulkValidationInterrupted($e, [
                    'processed' => $processed, 'by_status' => $byStatus, 'next_after_id' => $lastId,
                ]);
            }

            $processed++;
            $lastId = $purchase->id;
            $byStatus[$result['status']] = ($byStatus[$result['status']] ?? 0) + 1;
        }

        return [
            'processed' => $processed,
            'by_status' => $byStatus,
            'last_id' => $lastId,
            'stopped' => $stopped,
            'message' => $message,
        ];
    }

    private function periodQuery($period, $onlyPending)
    {
        $start = Carbon::createFromFormat('Ymd', $period.'01')->startOfMonth();
        $end = $start->copy()->endOfMonth();
        $company = Company::active();

        $query = Purchase::query()->setEagerLoads([])
            ->whereBetween('date_of_issue', [$start->toDateString(), $end->toDateString()])
            ->where('state_type_id', '!=', '11'); // voided purchases are not worth a SUNAT call

        if ($company) {
            $query->where('soap_type_id', $company->soap_type_id);
        }

        if ($onlyPending) {
            $query->where(function ($q) {
                $q->whereNull('sunat_validation_status')->orWhere('sunat_validation_status', self::STATUS_ERROR);
            });
        }

        return $query;
    }

    /** Seconds a bulk batch may run (overridable in tests). */
    protected function timeBudget()
    {
        return self::BULK_TIME_BUDGET;
    }

    protected function store($purchase, $status, array $detail)
    {
        $now = Carbon::now();
        $purchase->forceFill([
            'sunat_validation_status' => $status,
            'sunat_validation_detail' => $detail,
            'sunat_validated_at' => $now,
        ])->save();

        return [
            'status' => $status,
            'label' => self::LABELS[$status] ?? $status,
            'detail' => $detail,
            'validated_at' => $now->format('Y-m-d H:i:s'),
        ];
    }

    private function client()
    {
        if (!$this->client) {
            $company = Company::active();
            if (!$company) {
                throw new GreConfigurationException('No hay una empresa configurada.');
            }
            $this->client = new CpeValidationClient(CpeValidationClient::settingsForCompany($company));
        }

        return $this->client;
    }

    /** User-facing (Spanish) message for an exception thrown by this flow. */
    public static function friendlyError(GreException $e)
    {
        if ($e instanceof GreConfigurationException) {
            return $e->getMessage();
        }
        if ($e instanceof \App\CoreFacturalo\WS\Gre\Exceptions\GreConnectionException) {
            return 'No se pudo conectar con SUNAT. Verifique su conexión e intente nuevamente en unos minutos.';
        }
        if ($e instanceof \App\CoreFacturalo\WS\Gre\Exceptions\GreAuthException) {
            return 'SUNAT rechazó las credenciales de la consulta de validez de comprobantes. '
                .'Verifique el client_id y la clave generados en Menú SOL > Empresas > Comprobantes de pago > Consulta de Validez de Comprobantes de Pago.';
        }

        $status = (int) $e->getHttpStatus();
        if ($status === 429) {
            return 'SUNAT recibió demasiadas solicitudes. Espere unos minutos e intente nuevamente.';
        }
        if ($status >= 500) {
            return 'El servicio de consulta de validez de SUNAT no está disponible en este momento (HTTP '.$status.'). Intente más tarde.';
        }

        return $e->getDetailedMessage() ?: 'SUNAT no pudo validar el comprobante.';
    }
}
