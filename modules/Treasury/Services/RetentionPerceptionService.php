<?php

namespace Modules\Treasury\Services;

use App\Models\Tenant\Document;
use App\Models\Tenant\DocumentPayment;
use App\Models\Tenant\Purchase;
use App\Models\Tenant\PurchasePayment;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Treasury\Models\TreasuryMovement;
use Modules\Treasury\Models\TreasuryWithholding;
use Modules\Treasury\Support\DatabaseErrors;
use Modules\Treasury\Support\Money;

/**
 * IGV retention (CRE, tipo 20) and perception (CPE, tipo 40) support for Treasury Phase 4.
 *
 * This service NEVER emits a certificate: emission stays in the existing RetentionController /
 * PerceptionController flows. Treasury only (a) computes what must be retained/perceived with versioned
 * rates, (b) offers the existing emission screens, and (c) links the issued certificate to the payment,
 * tracking the retained/perceived amount as a payable to SUNAT (Form. 626 for retentions).
 *
 * Sources: docs/plans/tesoreria-investigacion-sunat.md sections 1a and 1b. Rates and thresholds always
 * come from treasury_tax_rates through TaxRateRepository.
 *
 * Never calls auth(); callers pass $userId and a $scope array
 * ['is_admin' => bool, 'user_id' => ?int, 'establishment_id' => ?int].
 */
class RetentionPerceptionService
{
    // Tolerance, certificate type codes and applicable document types are SUNAT/parameter data kept in
    // config('treasury.withholding_link_tolerance') and config('treasury.withholding.*') (single maintenance point).

    /** Upper bound of payments one issued CRE can be linked to in a single operation. */
    public const MAX_LINKED_PAYMENTS = 50;

    private $taxRateRepository;

    /** @var array<string, bool> */
    private $schemaCache = [];

    public function __construct(TaxRateRepository $taxRateRepository)
    {
        $this->taxRateRepository = $taxRateRepository;
    }

    // ------------------------------------------------------------------------------------------------
    // Agent flags
    // ------------------------------------------------------------------------------------------------

    /**
     * Flags come from the company record (set from the SUNAT padron); they are never inferred.
     *
     * @return array{is_retention_agent: bool, is_perception_agent: bool, is_good_taxpayer: bool}
     */
    public function getAgentFlags(): array
    {
        $flags = ['is_retention_agent' => false, 'is_perception_agent' => false, 'is_good_taxpayer' => false];

        if (!$this->hasTable('companies')) {
            return $flags;
        }

        $company = DB::connection('tenant')->table('companies')->first();
        if (!$company) {
            return $flags;
        }

        foreach (array_keys($flags) as $key) {
            $flags[$key] = isset($company->{$key}) && (bool) $company->{$key};
        }

        return $flags;
    }

    // ------------------------------------------------------------------------------------------------
    // Pure calculators (versioned rates)
    // ------------------------------------------------------------------------------------------------

    /**
     * IGV retention for ONE payment of an operation.
     *
     * The threshold applies to the OPERATION amount (comprobante total, converted to PEN) while the retention is
     * computed on each partial payment (SUNAT FAQ Q2/Q18, research section 1a).
     *
     * @return array{applicable: bool, reason: ?string, rate: ?float, min_operation: ?float, retention_amount: float,
     *     net_payment: float, operation_amount_pen: ?float, legal_reference: ?string}
     */
    public function computeRetention(
        float $paymentAmount,
        float $operationTotal,
        string $currency,
        ?float $exchangeRateSale,
        string $date
    ): array {
        $base = [
            'applicable' => false,
            'reason' => null,
            'rate' => null,
            'min_operation' => null,
            'retention_amount' => 0.0,
            'net_payment' => Money::round($paymentAmount),
            'operation_amount_pen' => null,
            'legal_reference' => null,
        ];

        $rule = $this->taxRateRepository->getRetentionRule($date);
        if ($rule === null) {
            $base['reason'] = 'No hay una tasa de retención vigente cargada para la fecha del pago.';

            return $base;
        }

        $base['rate'] = $rule['rate'];
        $base['min_operation'] = $rule['min_operation'];
        $base['legal_reference'] = $rule['legal_reference'];

        $operationPen = $this->toPen($operationTotal, $currency, $exchangeRateSale);
        if ($operationPen === null) {
            $base['reason'] = 'El comprobante está en moneda extranjera y no tiene tipo de cambio para evaluar el monto mínimo.';

            return $base;
        }
        $base['operation_amount_pen'] = $operationPen;

        // "Operation amount > threshold" (strictly greater).
        if ($operationPen <= $rule['min_operation']) {
            $base['reason'] = 'La operación no supera el monto mínimo para retención (S/ ' . Money::format($rule['min_operation']) . ').';

            return $base;
        }

        $retention = Money::round($paymentAmount * $rule['rate']);
        $base['applicable'] = $retention >= Money::MIN_AMOUNT;
        $base['retention_amount'] = $retention;
        $base['net_payment'] = Money::round($paymentAmount - $retention);
        if (!$base['applicable']) {
            $base['reason'] = 'El importe de la retención calculada es menor a 0.01.';
        }

        return $base;
    }

    /**
     * IGV perception for ONE collection (versioned rate by class code).
     *
     * @return array{applicable: bool, reason: ?string, rate: ?float, code: string, perception_amount: float,
     *     total_to_collect: float, legal_reference: ?string}
     */
    public function computePerception(float $collectedAmount, string $rateCode, string $date): array
    {
        $base = [
            'applicable' => false,
            'reason' => null,
            'rate' => null,
            'code' => $rateCode,
            'perception_amount' => 0.0,
            'total_to_collect' => Money::round($collectedAmount),
            'legal_reference' => null,
        ];

        $rule = $this->taxRateRepository->getPerceptionRule($rateCode, $date);
        if ($rule === null) {
            $base['reason'] = 'No hay una tasa de percepción vigente para la clase seleccionada en la fecha del cobro.';

            return $base;
        }

        $perception = Money::round($collectedAmount * $rule['rate']);
        $base['rate'] = $rule['rate'];
        $base['legal_reference'] = $rule['legal_reference'];
        $base['perception_amount'] = $perception;
        $base['total_to_collect'] = Money::round($collectedAmount + $perception);
        $base['applicable'] = $perception >= Money::MIN_AMOUNT;
        if (!$base['applicable']) {
            $base['reason'] = 'El importe de la percepción calculada es menor a 0.01.';
        }

        return $base;
    }

    // ------------------------------------------------------------------------------------------------
    // Offers (read only; they never emit anything)
    // ------------------------------------------------------------------------------------------------

    /**
     * Retention lines for each payment of a purchase. When the company is not a retention agent the
     * answer is `enabled = false` with no lines: no emission option must be shown.
     */
    public function getRetentionOffers(int $purchaseId, array $scope = []): array
    {
        $flags = $this->getAgentFlags();
        $result = [
            'enabled' => $flags['is_retention_agent'],
            'reason' => $flags['is_retention_agent'] ? null : 'La empresa no está registrada como agente de retención.',
            'purchase_id' => $purchaseId,
            'emit_url' => null,
            'lines' => [],
            'supplier_candidates' => [],
            'warnings' => [],
        ];

        if (!$flags['is_retention_agent']) {
            return $result;
        }

        $purchase = $this->loadOwnedSource('purchases', $purchaseId, $scope);

        $result['emit_url'] = '/retentions/create';
        $result['warnings'][] = 'Si el proveedor es Buen Contribuyente o agente de retención, la operación está excluida de la retención.';

        if (!in_array((string) $purchase->document_type_id, $this->retentionDocumentTypes(), true)) {
            $result['reason'] = 'La retención solo aplica a facturas.';

            return $result;
        }

        $currency = strtoupper(trim((string) ($purchase->currency_type_id ?: 'PEN')));
        $rateSale = $this->positiveOrNull($purchase->exchange_rate_sale ?? null);
        $payments = DB::connection('tenant')->table('purchase_payments')
            ->where('purchase_id', $purchaseId)
            ->orderBy('date_of_payment')
            ->orderBy('id')
            ->get();

        $linked = $this->loadActiveLinks(PurchasePayment::class, $payments->pluck('id')->all());

        foreach ($payments as $payment) {
            $date = $this->normalizeDate($payment->date_of_payment) ?? date('Y-m-d');
            $calc = $this->computeRetention((float) $payment->payment, (float) $purchase->total, $currency, $rateSale, $date);
            $link = $linked[(int) $payment->id] ?? null;

            $result['lines'][] = [
                'payment_id' => (int) $payment->id,
                'date' => $date,
                'payment' => Money::round((float) $payment->payment),
                'currency_type_id' => $currency,
                'applicable' => $calc['applicable'],
                'reason' => $calc['reason'],
                'rate_percent' => $calc['rate'] !== null ? round($calc['rate'] * 100, 3) : null,
                'retention_amount' => $calc['retention_amount'],
                'net_payment' => $calc['net_payment'],
                'linked' => $link !== null,
                'withholding_id' => $link ? (int) $link->id : null,
                'certificate' => $link ? trim((string) $link->series) . '-' . trim((string) $link->number) : null,
            ];
        }

        $result['supplier_candidates'] = $this->loadSupplierRetentionCandidates($purchase, $scope);

        return $result;
    }

    /**
     * Other unlinked, retention-applicable payments of the SAME supplier (same currency) from other purchases the
     * caller may manage. They let one issued CRE be linked to several payments (see linkIssuedRetentionPayments()).
     *
     * @return array<int, array<string, mixed>>
     */
    private function loadSupplierRetentionCandidates(object $purchase, array $scope): array
    {
        if (empty($purchase->supplier_id)) {
            return [];
        }

        $currency = strtoupper(trim((string) ($purchase->currency_type_id ?: 'PEN')));
        $isAdmin = array_key_exists('is_admin', $scope) ? (bool) $scope['is_admin'] : true;

        $query = DB::connection('tenant')->table('purchase_payments as pp')
            ->join('purchases as pu', 'pu.id', '=', 'pp.purchase_id')
            ->where('pu.supplier_id', $purchase->supplier_id)
            ->where('pu.id', '!=', $purchase->id)
            ->whereIn('pu.document_type_id', $this->retentionDocumentTypes())
            ->whereIn('pu.state_type_id', TreasuryBalanceService::ACCEPTED_STATE_TYPES)
            ->whereRaw("COALESCE(pu.currency_type_id, 'PEN') = ?", [$currency]);

        if (!$isAdmin) {
            $userId = (int) ($scope['user_id'] ?? 0);
            $establishmentId = (int) ($scope['establishment_id'] ?? 0);
            if ($userId <= 0 || $establishmentId <= 0) {
                return [];
            }
            $query->where('pu.user_id', $userId)->where('pu.establishment_id', $establishmentId);
        }

        $rows = $query->orderBy('pp.date_of_payment', 'desc')->orderBy('pp.id', 'desc')
            ->limit(self::MAX_LINKED_PAYMENTS * 2)
            ->get(['pp.id as payment_id', 'pp.payment', 'pp.date_of_payment', 'pu.id as purchase_id', 'pu.series', 'pu.number',
                'pu.total', 'pu.currency_type_id', 'pu.exchange_rate_sale']);

        $linked = $this->loadActiveLinks(PurchasePayment::class, $rows->pluck('payment_id')->all());

        $candidates = [];
        foreach ($rows as $row) {
            if (isset($linked[(int) $row->payment_id])) {
                continue;
            }
            $date = $this->normalizeDate($row->date_of_payment) ?? date('Y-m-d');
            $calc = $this->computeRetention((float) $row->payment, (float) $row->total, $currency, $this->positiveOrNull($row->exchange_rate_sale ?? null), $date);
            if (!$calc['applicable']) {
                continue;
            }
            $candidates[] = [
                'payment_id' => (int) $row->payment_id,
                'purchase_id' => (int) $row->purchase_id,
                'document' => trim((string) $row->series) . '-' . trim((string) $row->number),
                'date' => $date,
                'payment' => Money::round((float) $row->payment),
                'currency_type_id' => $currency,
                'retention_amount' => $calc['retention_amount'],
            ];
            if (count($candidates) >= self::MAX_LINKED_PAYMENTS) {
                break;
            }
        }

        return $candidates;
    }

    /**
     * Perception lines for each collection of a sale document (only when the company is a perception agent).
     */
    public function getPerceptionOffers(int $documentId, string $rateCode = 'general', array $scope = []): array
    {
        $flags = $this->getAgentFlags();
        $rateCode = $rateCode !== '' ? $rateCode : 'general';
        $result = [
            'enabled' => $flags['is_perception_agent'],
            'reason' => $flags['is_perception_agent'] ? null : 'La empresa no está registrada como agente de percepción.',
            'document_id' => $documentId,
            'rate_code' => $rateCode,
            'emit_url' => null,
            'lines' => [],
            'warnings' => [],
        ];

        if (!$flags['is_perception_agent']) {
            return $result;
        }

        $document = $this->loadOwnedSource('documents', $documentId, $scope);

        $result['emit_url'] = '/perceptions/create';
        $result['warnings'][] = 'La percepción aplica solo a los bienes del Apéndice 1 de la Ley 29173. Confirme que los ítems del comprobante están afectos.';

        if (!in_array((string) $document->document_type_id, $this->perceptionDocumentTypes(), true)) {
            $result['reason'] = 'La percepción solo aplica a facturas y boletas.';

            return $result;
        }

        // Operations subject to detracción are excluded from perception (research section 1b).
        $detraction = $this->hasColumn('documents', 'detraction') && $this->hasDetractionAmount($document->detraction ?? null);
        if ($detraction) {
            $result['reason'] = 'Las operaciones sujetas a detracción están excluidas de la percepción.';

            return $result;
        }

        $currency = strtoupper(trim((string) ($document->currency_type_id ?: 'PEN')));
        $payments = DB::connection('tenant')->table('document_payments')
            ->where('document_id', $documentId)
            ->orderBy('date_of_payment')
            ->orderBy('id')
            ->get();

        $linked = $this->loadActiveLinks(DocumentPayment::class, $payments->pluck('id')->all(), TreasuryWithholding::KIND_PERCEPTION_EMITTED);

        foreach ($payments as $payment) {
            $date = $this->normalizeDate($payment->date_of_payment) ?? date('Y-m-d');
            $calc = $this->computePerception((float) $payment->payment, $rateCode, $date);
            $link = $linked[(int) $payment->id] ?? null;

            $result['lines'][] = [
                'payment_id' => (int) $payment->id,
                'date' => $date,
                'payment' => Money::round((float) $payment->payment),
                'currency_type_id' => $currency,
                'applicable' => $calc['applicable'],
                'reason' => $calc['reason'],
                'rate_percent' => $calc['rate'] !== null ? round($calc['rate'] * 100, 3) : null,
                'perception_amount' => $calc['perception_amount'],
                'total_to_collect' => $calc['total_to_collect'],
                'linked' => $link !== null,
                'withholding_id' => $link ? (int) $link->id : null,
                'certificate' => $link ? trim((string) $link->series) . '-' . trim((string) $link->number) : null,
            ];
        }

        return $result;
    }

    // ------------------------------------------------------------------------------------------------
    // Registration / linking
    // ------------------------------------------------------------------------------------------------

    /**
     * A customer (retention agent) retained part of my invoice: register the CRE I received. It reduces the
     * net receivable of the invoice (ReceivablePayableService reads these records).
     *
     * @param array{series: string, number: string, date: string, amount: float|string, notes?: ?string} $payload
     */
    public function registerReceivedRetention(int $documentId, array $payload, int $userId, array $scope = []): TreasuryWithholding
    {
        $series = $this->requiredString($payload['series'] ?? null, 'series', 'La serie del comprobante de retención es obligatoria.', 10);
        $number = $this->requiredString($payload['number'] ?? null, 'number', 'El número del comprobante de retención es obligatorio.', 20);
        $date = $this->validateDate($payload['date'] ?? null, 'date');
        $amount = $this->validateAmount($payload['amount'] ?? null, 'amount');

        return DB::connection('tenant')->transaction(function () use ($documentId, $payload, $userId, $scope, $series, $number, $date, $amount) {
            // Lock the invoice row so concurrent registrations cannot exceed the invoice total.
            $document = DB::connection('tenant')->table('documents')->where('id', $documentId)->lockForUpdate()->first();
            if (!$document) {
                throw (new \Illuminate\Database\Eloquent\ModelNotFoundException())->setModel(Document::class, [$documentId]);
            }
            $this->assertSourceAccess($document, $scope);

            if (!in_array((string) $document->state_type_id, ReceivablePayableService::ACCEPTED_STATE_TYPES, true)) {
                throw ValidationException::withMessages([
                    'document' => ['El comprobante está anulado o no es válido para registrar una retención.'],
                ]);
            }
            if (!in_array((string) $document->document_type_id, $this->retentionDocumentTypes(), true)) {
                throw ValidationException::withMessages([
                    'document' => ['La retención solo aplica a facturas.'],
                ]);
            }

            $issueDate = $this->normalizeDate($document->date_of_issue);
            if ($issueDate !== null && $date < $issueDate) {
                throw ValidationException::withMessages([
                    'date' => ['La fecha de la retención no puede ser anterior a la emisión de la factura.'],
                ]);
            }

            $currency = strtoupper(trim((string) ($document->currency_type_id ?: 'PEN')));
            $rateSale = $this->positiveOrNull($document->exchange_rate_sale ?? null);
            $amountPen = $this->toPen($amount, $currency, $rateSale);
            if ($amountPen === null) {
                throw ValidationException::withMessages([
                    'amount' => ['El comprobante está en moneda extranjera y no tiene tipo de cambio.'],
                ]);
            }

            $existing = TreasuryWithholding::query()
                ->where('kind', TreasuryWithholding::KIND_RETENTION_RECEIVED)
                ->where('state', TreasuryWithholding::STATE_ACTIVE)
                ->where('parent_type', Document::class)
                ->where('parent_id', $documentId)
                ->lockForUpdate()
                ->get();

            foreach ($existing as $row) {
                if ($row->series === $series && $row->number === $number) {
                    throw ValidationException::withMessages([
                        'number' => ['Este comprobante de retención ya está registrado en la factura.'],
                    ]);
                }
            }

            $alreadyRegistered = Money::round((float) $existing->sum('amount'));
            $total = Money::round((float) $document->total);
            if (Money::round($alreadyRegistered + $amount) > $total) {
                throw ValidationException::withMessages([
                    'amount' => ['La retención registrada no puede superar el total de la factura.'],
                ]);
            }

            $person = !empty($document->customer_id) && $this->hasTable('persons')
                ? DB::connection('tenant')->table('persons')->where('id', $document->customer_id)->first(['id', 'name', 'number'])
                : null;

            $rule = $this->taxRateRepository->getRetentionRule($issueDate ?? $date);

            return TreasuryWithholding::create([
                'kind' => TreasuryWithholding::KIND_RETENTION_RECEIVED,
                'certificate_type' => $this->retentionCertificateType(),
                'series' => $series,
                'number' => $number,
                'date' => $date,
                'establishment_id' => $document->establishment_id ? (int) $document->establishment_id : null,
                'user_id' => $document->user_id ? (int) $document->user_id : $userId,
                'source_type' => Document::class,
                'source_id' => $documentId,
                'parent_type' => Document::class,
                'parent_id' => $documentId,
                'person_id' => $person ? (int) $person->id : null,
                'counterparty_document' => $person ? $person->number : null,
                'counterparty_name' => $person ? $person->name : null,
                'currency_type_id' => $currency,
                'base_amount' => $total,
                'rate' => $rule ? $rule['rate'] : null,
                'amount' => $amount,
                'amount_pen' => $amountPen,
                'payable_to_sunat' => false,
                'notes' => $this->nullableTrim($payload['notes'] ?? null),
                'state' => TreasuryWithholding::STATE_ACTIVE,
            ]);
        });
    }

    /**
     * Links a CRE issued with the existing Retention flow to the supplier payment that originated it.
     * Refuses when the company is not a retention agent.
     *
     * @param array{series: string, number: string} $payload
     */
    public function linkIssuedRetention(int $purchasePaymentId, array $payload, int $userId, array $scope = []): TreasuryWithholding
    {
        return $this->linkIssuedRetentionPayments([$purchasePaymentId], $payload, $userId, $scope)[0];
    }

    /**
     * Links ONE issued CRE to one or several payments of the SAME supplier (a certificate may cover several
     * payments). The per-payment retentions computed with the versioned rate must add up to the certificate total
     * within `treasury.withholding_link_tolerance`. One `treasury_withholdings` row is created per payment, all
     * sharing the certificate.
     *
     * Concurrency: payment rows are locked in id order, then the certificate row (BEFORE any usage check), then the
     * existing active links, so two requests serialize on the certificate. Two UNIQUE DB guards back it:
     * `active_link_key` ("retention:{certificate}:{payment}") per (certificate, payment) and `cert_active_key`
     * ("retention:{certificate}", set on the FIRST row of the active set only, NULL on the rest and after voiding) per
     * certificate, so even without the row lock two requests cannot link the same certificate to different payment
     * sets. A certificate already linked to ANY active set is refused: the link set is atomic and can only change by
     * voiding it (which releases every row of the certificate).
     *
     * @param array<int, int|string> $purchasePaymentIds
     * @param array{series: string, number: string} $payload
     *
     * @return array<int, TreasuryWithholding> rows in ascending payment id order
     */
    public function linkIssuedRetentionPayments(array $purchasePaymentIds, array $payload, int $userId, array $scope = []): array
    {
        $flags = $this->getAgentFlags();
        if (!$flags['is_retention_agent']) {
            throw ValidationException::withMessages([
                'series' => ['La empresa no está registrada como agente de retención.'],
            ]);
        }

        $series = $this->requiredString($payload['series'] ?? null, 'series', 'La serie del comprobante de retención es obligatoria.', 10);
        $number = $this->requiredString($payload['number'] ?? null, 'number', 'El número del comprobante de retención es obligatorio.', 20);

        $ids = array_values(array_unique(array_filter(array_map('intval', $purchasePaymentIds), function ($id) {
            return $id > 0;
        })));
        sort($ids);
        if (empty($ids)) {
            throw ValidationException::withMessages([
                'payment_ids' => ['Seleccione al menos un pago para enlazar el comprobante.'],
            ]);
        }
        if (count($ids) > self::MAX_LINKED_PAYMENTS) {
            throw ValidationException::withMessages([
                'payment_ids' => ['Un comprobante no puede enlazarse a más de ' . self::MAX_LINKED_PAYMENTS . ' pagos.'],
            ]);
        }
        $multiple = count($ids) > 1;

        return DB::connection('tenant')->transaction(function () use ($ids, $multiple, $series, $number, $userId, $scope) {
            $payments = DB::connection('tenant')->table('purchase_payments')
                ->whereIn('id', $ids)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            foreach ($ids as $id) {
                if (!$payments->has($id)) {
                    throw (new \Illuminate\Database\Eloquent\ModelNotFoundException())->setModel(PurchasePayment::class, [$id]);
                }
            }

            $purchases = DB::connection('tenant')->table('purchases')
                ->whereIn('id', $payments->pluck('purchase_id')->unique()->values()->all())
                ->get()
                ->keyBy('id');

            $context = [];
            foreach ($ids as $id) {
                $payment = $payments->get($id);
                $purchase = $purchases->get($payment->purchase_id);
                if (!$purchase) {
                    throw (new \Illuminate\Database\Eloquent\ModelNotFoundException())->setModel(Purchase::class, [$payment->purchase_id]);
                }
                $this->assertSourceAccess($purchase, $scope);

                if (!in_array((string) $purchase->document_type_id, $this->retentionDocumentTypes(), true)) {
                    throw ValidationException::withMessages([
                        'payment' => ['La retención solo aplica a facturas.'],
                    ]);
                }
                $context[$id] = [$payment, $purchase];
            }

            if ($multiple) {
                $suppliers = array_unique(array_map(function ($row) {
                    return (int) ($row[1]->supplier_id ?? 0);
                }, $context));
                if (count($suppliers) !== 1 || reset($suppliers) <= 0) {
                    throw ValidationException::withMessages([
                        'payment_ids' => ['Todos los pagos enlazados a un mismo comprobante deben ser del mismo proveedor.'],
                    ]);
                }

                $currencies = array_unique(array_map(function ($row) {
                    return strtoupper(trim((string) ($row[1]->currency_type_id ?: 'PEN')));
                }, $context));
                if (count($currencies) !== 1) {
                    throw ValidationException::withMessages([
                        'payment_ids' => ['Todos los pagos enlazados a un mismo comprobante deben estar en la misma moneda.'],
                    ]);
                }
            }

            // Lock the certificate row FIRST: every usage check below must run after the requests serialize on it.
            $certificate = $this->findCertificate('retentions', $series, $number, 'supplier');

            $duplicatePayment = TreasuryWithholding::query()
                ->where('kind', TreasuryWithholding::KIND_RETENTION_EMITTED)
                ->where('state', TreasuryWithholding::STATE_ACTIVE)
                ->where('source_type', PurchasePayment::class)
                ->whereIn('source_id', $ids)
                ->lockForUpdate()
                ->exists();
            if ($duplicatePayment) {
                throw ValidationException::withMessages([
                    'payment' => [$multiple
                        ? 'Alguno de los pagos seleccionados ya tiene un comprobante de retención enlazado.'
                        : 'Este pago ya tiene un comprobante de retención enlazado.'],
                ]);
            }

            $alreadyUsed = TreasuryWithholding::query()
                ->where('kind', TreasuryWithholding::KIND_RETENTION_EMITTED)
                ->where('state', TreasuryWithholding::STATE_ACTIVE)
                ->where('retention_id', $certificate->id)
                ->lockForUpdate()
                ->exists();
            if ($alreadyUsed) {
                throw ValidationException::withMessages([
                    'number' => ['Este comprobante de retención ya está enlazado a otro pago.'],
                ]);
            }

            $calcs = [];
            $computedTotal = 0.0;
            foreach ($context as $id => [$payment, $purchase]) {
                $date = $this->normalizeDate($payment->date_of_payment) ?? date('Y-m-d');
                $currency = strtoupper(trim((string) ($purchase->currency_type_id ?: 'PEN')));
                $rateSale = $this->positiveOrNull($purchase->exchange_rate_sale ?? null);
                $calc = $this->computeRetention((float) $payment->payment, (float) $purchase->total, $currency, $rateSale, $date);
                if (!$calc['applicable']) {
                    $reason = $calc['reason'] ?: 'Este pago no está afecto a retención.';
                    throw ValidationException::withMessages([
                        'payment' => [$multiple ? 'Pago #' . $id . ': ' . $reason : $reason],
                    ]);
                }
                $calcs[$id] = [$calc, $date, $currency, $rateSale];
                $computedTotal = Money::round($computedTotal + $calc['retention_amount']);
            }

            $certificateAmount = Money::round((float) ($certificate->total_retention ?? 0));
            if (abs($certificateAmount - $computedTotal) > $this->linkTolerance()) {
                throw ValidationException::withMessages([
                    'number' => [
                        $multiple
                            ? 'El monto del comprobante de retención (' . Money::format($certificateAmount)
                                . ') no coincide con la suma de las retenciones calculadas para los pagos seleccionados (' . Money::format($computedTotal) . ').'
                            : 'El monto del comprobante de retención (' . Money::format($certificateAmount)
                                . ') no coincide con la retención calculada para el pago (' . Money::format($computedTotal) . ').',
                    ],
                ]);
            }

            $firstPurchase = reset($context)[1];
            $this->assertCounterpartyMatches($certificate->supplier ?? null, $firstPurchase->supplier_id ?? null, 'number');

            $person = !empty($firstPurchase->supplier_id) && $this->hasTable('persons')
                ? DB::connection('tenant')->table('persons')->where('id', $firstPurchase->supplier_id)->first(['id', 'name', 'number'])
                : null;

            $links = [];
            $isFirstLink = true;
            foreach ($context as $id => [$payment, $purchase]) {
                [$calc, $date, $currency, $rateSale] = $calcs[$id];
                $amountPen = $this->toPen($calc['retention_amount'], $currency, $rateSale) ?? $calc['retention_amount'];

                $links[] = $this->createLink([
                    'kind' => TreasuryWithholding::KIND_RETENTION_EMITTED,
                    'certificate_type' => $this->retentionCertificateType(),
                    'series' => $series,
                    'number' => $number,
                    'date' => $this->normalizeDate($certificate->date_of_issue) ?? $date,
                    'establishment_id' => $purchase->establishment_id ? (int) $purchase->establishment_id : null,
                    'user_id' => $purchase->user_id ? (int) $purchase->user_id : $userId,
                    'source_type' => PurchasePayment::class,
                    'source_id' => (int) $id,
                    'parent_type' => Purchase::class,
                    'parent_id' => (int) $purchase->id,
                    'retention_id' => (int) $certificate->id,
                    'active_link_key' => 'retention:' . (int) $certificate->id . ':' . (int) $id,
                    // Certificate-level guard: only the first row of the set carries it.
                    'cert_active_key' => $isFirstLink ? 'retention:' . (int) $certificate->id : null,
                    'person_id' => $person ? (int) $person->id : null,
                    'counterparty_document' => $person ? $person->number : null,
                    'counterparty_name' => $person ? $person->name : null,
                    'currency_type_id' => $currency,
                    'base_amount' => Money::round((float) $payment->payment),
                    'rate' => $calc['rate'],
                    'amount' => $calc['retention_amount'],
                    'amount_pen' => $amountPen,
                    'payable_to_sunat' => true,
                    'state' => TreasuryWithholding::STATE_ACTIVE,
                ]);
                $isFirstLink = false;
            }

            return $links;
        });
    }

    /**
     * Links a CPE issued with the existing Perception flow to the customer collection that originated it.
     * Refuses when the company is not a perception agent.
     *
     * @param array{series: string, number: string, rate_code?: string} $payload
     */
    public function linkIssuedPerception(int $documentPaymentId, array $payload, int $userId, array $scope = []): TreasuryWithholding
    {
        $flags = $this->getAgentFlags();
        if (!$flags['is_perception_agent']) {
            throw ValidationException::withMessages([
                'series' => ['La empresa no está registrada como agente de percepción.'],
            ]);
        }

        $series = $this->requiredString($payload['series'] ?? null, 'series', 'La serie del comprobante de percepción es obligatoria.', 10);
        $number = $this->requiredString($payload['number'] ?? null, 'number', 'El número del comprobante de percepción es obligatorio.', 20);
        $rateCode = trim((string) ($payload['rate_code'] ?? 'general')) ?: 'general';

        return DB::connection('tenant')->transaction(function () use ($documentPaymentId, $series, $number, $rateCode, $userId, $scope) {
            $payment = DB::connection('tenant')->table('document_payments')->where('id', $documentPaymentId)->lockForUpdate()->first();
            if (!$payment) {
                throw (new \Illuminate\Database\Eloquent\ModelNotFoundException())->setModel(DocumentPayment::class, [$documentPaymentId]);
            }

            $document = DB::connection('tenant')->table('documents')->where('id', $payment->document_id)->first();
            if (!$document) {
                throw (new \Illuminate\Database\Eloquent\ModelNotFoundException())->setModel(Document::class, [$payment->document_id]);
            }
            $this->assertSourceAccess($document, $scope);

            if (!in_array((string) $document->document_type_id, $this->perceptionDocumentTypes(), true)) {
                throw ValidationException::withMessages([
                    'payment' => ['La percepción solo aplica a facturas y boletas.'],
                ]);
            }

            if ($this->hasColumn('documents', 'detraction') && $this->hasDetractionAmount($document->detraction ?? null)) {
                throw ValidationException::withMessages([
                    'payment' => ['Las operaciones sujetas a detracción están excluidas de la percepción.'],
                ]);
            }

            $duplicatePayment = TreasuryWithholding::query()
                ->where('kind', TreasuryWithholding::KIND_PERCEPTION_EMITTED)
                ->where('state', TreasuryWithholding::STATE_ACTIVE)
                ->where('source_type', DocumentPayment::class)
                ->where('source_id', $documentPaymentId)
                ->exists();
            if ($duplicatePayment) {
                throw ValidationException::withMessages([
                    'payment' => ['Este cobro ya tiene un comprobante de percepción enlazado.'],
                ]);
            }

            $certificate = $this->findCertificate('perceptions', $series, $number, 'customer');

            $alreadyUsed = TreasuryWithholding::query()
                ->where('kind', TreasuryWithholding::KIND_PERCEPTION_EMITTED)
                ->where('state', TreasuryWithholding::STATE_ACTIVE)
                ->where('perception_id', $certificate->id)
                ->exists();
            if ($alreadyUsed) {
                throw ValidationException::withMessages([
                    'number' => ['Este comprobante de percepción ya está enlazado a otro cobro.'],
                ]);
            }

            $date = $this->normalizeDate($payment->date_of_payment) ?? date('Y-m-d');
            $currency = strtoupper(trim((string) ($document->currency_type_id ?: 'PEN')));
            $rateSale = $this->positiveOrNull($document->exchange_rate_sale ?? null);
            $calc = $this->computePerception((float) $payment->payment, $rateCode, $date);
            if (!$calc['applicable']) {
                throw ValidationException::withMessages([
                    'payment' => [$calc['reason'] ?: 'Este cobro no está afecto a percepción.'],
                ]);
            }

            $certificateAmount = Money::round((float) ($certificate->total_perception ?? 0));
            if (abs($certificateAmount - $calc['perception_amount']) > $this->linkTolerance()) {
                throw ValidationException::withMessages([
                    'number' => [
                        'El monto del comprobante de percepción (' . Money::format($certificateAmount)
                        . ') no coincide con la percepción calculada para el cobro (' . Money::format($calc['perception_amount']) . ').',
                    ],
                ]);
            }

            $this->assertCounterpartyMatches($certificate->customer ?? null, $document->customer_id ?? null, 'number');

            $amountPen = $this->toPen($calc['perception_amount'], $currency, $rateSale) ?? $calc['perception_amount'];
            $person = !empty($document->customer_id) && $this->hasTable('persons')
                ? DB::connection('tenant')->table('persons')->where('id', $document->customer_id)->first(['id', 'name', 'number'])
                : null;

            return $this->createLink([
                'kind' => TreasuryWithholding::KIND_PERCEPTION_EMITTED,
                'certificate_type' => $this->perceptionCertificateType(),
                'series' => $series,
                'number' => $number,
                'date' => $this->normalizeDate($certificate->date_of_issue) ?? $date,
                'establishment_id' => $document->establishment_id ? (int) $document->establishment_id : null,
                'user_id' => $document->user_id ? (int) $document->user_id : $userId,
                'source_type' => DocumentPayment::class,
                'source_id' => $documentPaymentId,
                'parent_type' => Document::class,
                'parent_id' => (int) $document->id,
                'perception_id' => (int) $certificate->id,
                'active_link_key' => 'perception:' . (int) $certificate->id,
                'person_id' => $person ? (int) $person->id : null,
                'counterparty_document' => $person ? $person->number : null,
                'counterparty_name' => $person ? $person->name : null,
                'currency_type_id' => $currency,
                'base_amount' => Money::round((float) $payment->payment),
                'rate' => $calc['rate'],
                'amount' => $calc['perception_amount'],
                'amount_pen' => $amountPen,
                'payable_to_sunat' => true,
                'state' => TreasuryWithholding::STATE_ACTIVE,
            ]);
        });
    }

    /**
     * Inserts an emitted-certificate link. The unique `active_link_key` is the DB-level guard against the same
     * CRE/CPE being linked twice; a duplicate-key error becomes a domain error instead of a 500.
     */
    private function createLink(array $attributes): TreasuryWithholding
    {
        try {
            return TreasuryWithholding::create($attributes);
        } catch (QueryException $e) {
            if (!DatabaseErrors::isDuplicateKey($e)) {
                throw $e;
            }

            throw ValidationException::withMessages([
                'number' => ['Este comprobante ya está enlazado a otro pago o cobro.'],
            ]);
        }
    }

    public function voidWithholding(int $id, string $reason, int $userId, array $scope = []): TreasuryWithholding
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            throw ValidationException::withMessages([
                'void_reason' => ['El motivo de anulación es obligatorio (mínimo 3 caracteres).'],
            ]);
        }

        return DB::connection('tenant')->transaction(function () use ($id, $reason, $userId, $scope) {
            $row = TreasuryWithholding::query()->whereKey($id)->lockForUpdate()->first();
            if (!$row) {
                throw (new \Illuminate\Database\Eloquent\ModelNotFoundException())->setModel(TreasuryWithholding::class, [$id]);
            }

            $this->assertWithholdingAccess($row, $scope);

            if ($row->state === TreasuryWithholding::STATE_VOIDED) {
                throw ValidationException::withMessages([
                    'withholding' => ['El registro ya se encuentra anulado.'],
                ]);
            }
            // A certificate linked to several payments is one unit: voiding any of its links releases ALL of them.
            $group = collect([$row]);
            if ($row->kind === TreasuryWithholding::KIND_RETENTION_EMITTED && $row->retention_id) {
                $siblings = TreasuryWithholding::query()
                    ->where('kind', TreasuryWithholding::KIND_RETENTION_EMITTED)
                    ->where('state', TreasuryWithholding::STATE_ACTIVE)
                    ->where('retention_id', $row->retention_id)
                    ->where('id', '!=', $row->id)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
                foreach ($siblings as $sibling) {
                    $this->assertWithholdingAccess($sibling, $scope);
                }
                $group = $group->concat($siblings);
            }

            foreach ($group as $member) {
                if ($member->settled_movement_id) {
                    throw ValidationException::withMessages([
                        'withholding' => ['El monto ya fue pagado a SUNAT; anule primero el pago de tributos.'],
                    ]);
                }
            }

            foreach ($group as $member) {
                $member->state = TreasuryWithholding::STATE_VOIDED;
                // Free the certificate so it can be linked again.
                $member->active_link_key = null;
                $member->cert_active_key = null;
                $member->voided_at = now();
                $member->voided_by = $userId;
                $member->void_reason = $reason;
                $member->save();
            }

            return $row;
        });
    }

    /**
     * Marks retained/perceived amounts as paid to SUNAT by linking an active `tax_payment` movement.
     *
     * @param array<int, int> $withholdingIds
     */
    public function settleWithTaxPayment(array $withholdingIds, int $movementId): int
    {
        $ids = array_values(array_unique(array_map('intval', $withholdingIds)));
        if (empty($ids)) {
            throw ValidationException::withMessages([
                'ids' => ['Seleccione al menos un registro para liquidar.'],
            ]);
        }

        return DB::connection('tenant')->transaction(function () use ($ids, $movementId) {
            $movement = TreasuryMovement::query()->whereKey($movementId)->lockForUpdate()->first();
            if (!$movement || $movement->kind !== 'tax_payment' || $movement->state !== TreasuryMovement::STATE_ACTIVE) {
                throw ValidationException::withMessages([
                    'movement_id' => ['Seleccione un movimiento activo de tipo pago de tributos.'],
                ]);
            }

            $rows = TreasuryWithholding::query()->whereIn('id', $ids)->lockForUpdate()->get();
            if ($rows->count() !== count($ids)) {
                throw ValidationException::withMessages([
                    'ids' => ['Alguno de los registros seleccionados no existe.'],
                ]);
            }

            // Already settled by THIS movement (active rows): the coverage check must count them too.
            $alreadyLinked = Money::round((float) TreasuryWithholding::query()
                ->where('settled_movement_id', $movement->getKey())
                ->where('state', TreasuryWithholding::STATE_ACTIVE)
                ->lockForUpdate()
                ->sum('amount_pen'));

            $total = 0.0;
            foreach ($rows as $row) {
                if (!$row->payable_to_sunat || $row->state !== TreasuryWithholding::STATE_ACTIVE || $row->settled_movement_id) {
                    throw ValidationException::withMessages([
                        'ids' => ['Solo se pueden liquidar registros activos, pendientes de pago a SUNAT.'],
                    ]);
                }
                $total = Money::round($total + (float) $row->amount_pen);
            }

            // The tax payment must cover what is being settled; it may cover more (other taxes).
            $required = Money::round($alreadyLinked + $total);
            if (Money::round((float) $movement->amount_pen) + 0.005 < $required) {
                throw ValidationException::withMessages([
                    'movement_id' => [
                        'El pago de tributos (S/ ' . Money::format((float) $movement->amount_pen) . ') no cubre el monto seleccionado (S/ ' . Money::format($total) . ')'
                        . ($alreadyLinked > 0 ? ' más lo ya liquidado con este pago (S/ ' . Money::format($alreadyLinked) . ')' : '') . '.',
                    ],
                ]);
            }

            foreach ($rows as $row) {
                $row->settled_movement_id = $movement->getKey();
                $row->save();
            }

            return $rows->count();
        });
    }

    // ------------------------------------------------------------------------------------------------
    // Listing
    // ------------------------------------------------------------------------------------------------

    /**
     * @param array{kind?: ?string, state?: ?string, date_start?: ?string, date_end?: ?string, is_admin?: bool,
     *     user_id?: ?int, establishment_id?: ?int, page?: int, per_page?: int} $filters
     * @return array{rows: array, sunat_payable: array, meta: array}
     */
    public function listWithholdings(array $filters = []): array
    {
        $empty = [
            'rows' => [],
            'sunat_payable' => ['total_pen' => 0.0, 'by_kind' => [], 'by_period' => []],
            'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 20, 'total' => 0],
        ];

        if (!$this->hasTable('treasury_withholdings')) {
            return $empty;
        }

        $isAdmin = array_key_exists('is_admin', $filters) ? (bool) $filters['is_admin'] : true;
        $userId = isset($filters['user_id']) && $filters['user_id'] !== '' ? (int) $filters['user_id'] : null;
        if (!$isAdmin && $userId === null) {
            return $empty;
        }

        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 20)));
        $page = max(1, (int) ($filters['page'] ?? 1));

        $query = TreasuryWithholding::query();
        if (!$isAdmin) {
            $query->where('user_id', $userId)->where('establishment_id', (int) ($filters['establishment_id'] ?? 0));
        } else {
            if ($userId !== null) {
                $query->where('user_id', $userId);
            }
            if (isset($filters['establishment_id']) && $filters['establishment_id'] !== null && $filters['establishment_id'] !== '') {
                $query->where('establishment_id', (int) $filters['establishment_id']);
            }
        }

        if (!empty($filters['kind']) && in_array($filters['kind'], TreasuryWithholding::KINDS, true)) {
            $query->where('kind', $filters['kind']);
        }
        $state = !empty($filters['state']) ? (string) $filters['state'] : 'active';
        if (in_array($state, [TreasuryWithholding::STATE_ACTIVE, TreasuryWithholding::STATE_VOIDED], true)) {
            $query->where('state', $state);
        }
        if (!empty($filters['date_start'])) {
            $query->where('date', '>=', (string) $filters['date_start']);
        }
        if (!empty($filters['date_end'])) {
            $query->where('date', '<=', (string) $filters['date_end']);
        }

        $payableRows = (clone $query)
            ->where('payable_to_sunat', true)
            ->where('state', TreasuryWithholding::STATE_ACTIVE)
            ->whereNull('settled_movement_id')
            ->get(['kind', 'date', 'amount_pen']);

        $sunat = ['total_pen' => 0.0, 'by_kind' => [], 'by_period' => []];
        foreach ($payableRows as $row) {
            $amount = (float) $row->amount_pen;
            $period = $row->date ? $row->date->format('Y-m') : 'n/a';
            $sunat['total_pen'] = Money::round($sunat['total_pen'] + $amount);
            $sunat['by_kind'][$row->kind] = Money::round(($sunat['by_kind'][$row->kind] ?? 0.0) + $amount);
            $sunat['by_period'][$period] = Money::round(($sunat['by_period'][$period] ?? 0.0) + $amount);
        }
        ksort($sunat['by_period']);

        $total = (clone $query)->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($page, $lastPage);

        $rows = $query->orderBy('date', 'desc')->orderBy('id', 'desc')->forPage($page, $perPage)->get()->map(function ($row) {
            return [
                'id' => (int) $row->id,
                'kind' => $row->kind,
                'certificate_type' => $row->certificate_type,
                'certificate' => trim((string) $row->series) . '-' . trim((string) $row->number),
                'date' => $row->date ? $row->date->format('Y-m-d') : null,
                'parent_type' => $row->parent_type === Document::class ? 'document' : 'purchase',
                'parent_id' => (int) $row->parent_id,
                'counterparty_name' => $row->counterparty_name,
                'counterparty_document' => $row->counterparty_document,
                'currency_type_id' => $row->currency_type_id,
                'base_amount' => Money::round((float) $row->base_amount),
                'rate_percent' => $row->rate !== null ? round($row->rate * 100, 3) : null,
                'amount' => Money::round((float) $row->amount),
                'amount_pen' => Money::round((float) $row->amount_pen),
                'payable_to_sunat' => (bool) $row->payable_to_sunat,
                'settled' => $row->settled_movement_id !== null,
                'state' => $row->state,
            ];
        })->values()->all();

        return [
            'rows' => $rows,
            'sunat_payable' => $sunat,
            'meta' => ['current_page' => $page, 'last_page' => $lastPage, 'per_page' => $perPage, 'total' => $total],
        ];
    }

    // ------------------------------------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------------------------------------

    private function linkTolerance(): float
    {
        return (float) config('treasury.withholding_link_tolerance', 0.0);
    }

    private function retentionCertificateType(): string
    {
        return (string) config('treasury.withholding.retention_certificate_type');
    }

    private function perceptionCertificateType(): string
    {
        return (string) config('treasury.withholding.perception_certificate_type');
    }

    /** @return array<int, string> */
    private function retentionDocumentTypes(): array
    {
        return array_map('strval', (array) config('treasury.withholding.retention_document_types', []));
    }

    /** @return array<int, string> */
    private function perceptionDocumentTypes(): array
    {
        return array_map('strval', (array) config('treasury.withholding.perception_document_types', []));
    }

    public function assertSourceAccess(object $source, array $scope): void
    {
        $isAdmin = array_key_exists('is_admin', $scope) ? (bool) $scope['is_admin'] : true;
        if ($isAdmin) {
            return;
        }

        $userId = (int) ($scope['user_id'] ?? 0);
        $establishmentId = (int) ($scope['establishment_id'] ?? 0);

        // A non-admin without an establishment must never match NULL-establishment sources.
        if ($userId <= 0
            || $establishmentId <= 0
            || (int) ($source->user_id ?? 0) !== $userId
            || (int) ($source->establishment_id ?? 0) !== $establishmentId) {
            throw new \Illuminate\Auth\Access\AuthorizationException('No tiene permiso para gestionar este comprobante.');
        }
    }

    public function assertWithholdingAccess(TreasuryWithholding $row, array $scope): void
    {
        $isAdmin = array_key_exists('is_admin', $scope) ? (bool) $scope['is_admin'] : true;
        if ($isAdmin) {
            return;
        }

        $userId = (int) ($scope['user_id'] ?? 0);
        $establishmentId = (int) ($scope['establishment_id'] ?? 0);

        // A non-admin without an establishment must never match NULL-establishment records.
        if ($userId <= 0
            || $establishmentId <= 0
            || (int) $row->user_id !== $userId
            || (int) $row->establishment_id !== $establishmentId) {
            throw new \Illuminate\Auth\Access\AuthorizationException('No tiene permiso para gestionar este registro.');
        }
    }

    private function loadOwnedSource(string $table, int $id, array $scope): object
    {
        $row = DB::connection('tenant')->table($table)->where('id', $id)->first();
        if (!$row) {
            throw (new \Illuminate\Database\Eloquent\ModelNotFoundException())->setModel($table, [$id]);
        }
        $this->assertSourceAccess($row, $scope);

        return $row;
    }

    /**
     * @param array<int, int> $paymentIds
     * @return array<int, TreasuryWithholding>
     */
    private function loadActiveLinks(string $sourceClass, array $paymentIds, string $kind = TreasuryWithholding::KIND_RETENTION_EMITTED): array
    {
        if (empty($paymentIds) || !$this->hasTable('treasury_withholdings')) {
            return [];
        }

        $map = [];
        $rows = TreasuryWithholding::query()
            ->where('kind', $kind)
            ->where('state', TreasuryWithholding::STATE_ACTIVE)
            ->where('source_type', $sourceClass)
            ->whereIn('source_id', $paymentIds)
            ->get();
        foreach ($rows as $row) {
            $map[(int) $row->source_id] = $row;
        }

        return $map;
    }

    private function findCertificate(string $table, string $series, string $number, string $partyColumn): object
    {
        if (!$this->hasTable($table)) {
            throw ValidationException::withMessages([
                'number' => ['No se encontró el comprobante indicado.'],
            ]);
        }

        // Lock the certificate row so two concurrent links of the same CRE/CPE serialize before the usage check.
        $certificate = DB::connection('tenant')->table($table)
            ->where('series', $series)
            ->where('number', $number)
            ->lockForUpdate()
            ->first();

        if (!$certificate) {
            throw ValidationException::withMessages([
                'number' => ['No se encontró un comprobante ' . $series . '-' . $number . '. Emítalo primero desde el flujo existente.'],
            ]);
        }

        // 09 = rechazado, 11 = anulado: neither is a valid certificate.
        if (in_array((string) ($certificate->state_type_id ?? ''), ['09', '11'], true)) {
            throw ValidationException::withMessages([
                'number' => ['El comprobante indicado está anulado o fue rechazado por SUNAT.'],
            ]);
        }

        return $certificate;
    }

    /**
     * When the certificate carries the counterparty document number, it must match the invoice's counterparty.
     */
    private function assertCounterpartyMatches($certificateParty, $personId, string $field): void
    {
        if ($certificateParty === null || $personId === null || !$this->hasTable('persons')) {
            return;
        }

        $decoded = is_string($certificateParty) ? json_decode($certificateParty, true) : (array) $certificateParty;
        $certificateNumber = is_array($decoded) ? trim((string) ($decoded['number'] ?? '')) : '';
        if ($certificateNumber === '') {
            return;
        }

        $person = DB::connection('tenant')->table('persons')->where('id', $personId)->first(['number']);
        if ($person && trim((string) $person->number) !== '' && trim((string) $person->number) !== $certificateNumber) {
            throw ValidationException::withMessages([
                $field => ['El documento de la contraparte del comprobante no coincide con el del comprobante de compra/venta.'],
            ]);
        }
    }

    private function hasDetractionAmount($raw): bool
    {
        if ($raw === null || $raw === '') {
            return false;
        }
        $decoded = is_string($raw) ? json_decode($raw, true) : (is_object($raw) ? json_decode(json_encode($raw), true) : $raw);

        return is_array($decoded) && isset($decoded['amount']) && is_numeric($decoded['amount']) && (float) $decoded['amount'] > 0;
    }

    private function toPen(float $amount, string $currency, ?float $exchangeRateSale): ?float
    {
        if ($currency === 'PEN') {
            return Money::round($amount);
        }
        if ($exchangeRateSale !== null && $exchangeRateSale > 0) {
            return Money::round($amount * $exchangeRateSale);
        }

        return null;
    }

    private function positiveOrNull($value): ?float
    {
        return ($value !== null && is_numeric($value) && (float) $value > 0) ? (float) $value : null;
    }

    private function requiredString($value, string $field, string $message, int $max): string
    {
        $trimmed = $this->nullableTrim($value);
        if ($trimmed === null) {
            throw ValidationException::withMessages([$field => [$message]]);
        }
        if (mb_strlen($trimmed) > $max) {
            throw ValidationException::withMessages([$field => ['El valor no debe exceder ' . $max . ' caracteres.']]);
        }

        return $trimmed;
    }

    private function validateDate($value, string $field): string
    {
        $trimmed = trim((string) $value);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $trimmed)) {
            throw ValidationException::withMessages([$field => ['La fecha debe tener el formato YYYY-MM-DD.']]);
        }
        if ($trimmed > date('Y-m-d')) {
            throw ValidationException::withMessages([$field => ['No se permiten registros con fecha futura.']]);
        }

        return $trimmed;
    }

    private function validateAmount($raw, string $field): float
    {
        if (!is_numeric($raw)) {
            throw ValidationException::withMessages([$field => ['El monto debe ser un valor numérico válido.']]);
        }
        $rounded = Money::round((float) $raw);
        if ($rounded < Money::MIN_AMOUNT || $rounded > Money::MAX_AMOUNT) {
            throw ValidationException::withMessages([$field => ['El monto debe estar entre 0.01 y 9,999,999,999.99.']]);
        }

        return $rounded;
    }

    private function normalizeDate($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return Carbon::parse($value)->format('Y-m-d');
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function nullableTrim($value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function hasTable(string $table): bool
    {
        if (isset($this->schemaCache[\Modules\Treasury\Support\TenantCacheKey::prefix() . 't:' . $table])) {
            return $this->schemaCache[\Modules\Treasury\Support\TenantCacheKey::prefix() . 't:' . $table];
        }
        if (!Model::getConnectionResolver()) {
            return false;
        }
        try {
            return $this->schemaCache[\Modules\Treasury\Support\TenantCacheKey::prefix() . 't:' . $table] = DB::connection('tenant')->getSchemaBuilder()->hasTable($table);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function hasColumn(string $table, string $column): bool
    {
        $key = \Modules\Treasury\Support\TenantCacheKey::prefix() . 'c:' . $table . '.' . $column;
        if (isset($this->schemaCache[$key])) {
            return $this->schemaCache[$key];
        }
        if (!$this->hasTable($table)) {
            return false;
        }
        try {
            return $this->schemaCache[$key] = DB::connection('tenant')->getSchemaBuilder()->hasColumn($table, $column);
        } catch (\Throwable $e) {
            return false;
        }
    }
}
