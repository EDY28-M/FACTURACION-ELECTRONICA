<?php

namespace Modules\Treasury\Services;

use App\Models\Tenant\BankAccount;
use App\Models\Tenant\Cash;
use App\Models\Tenant\ExchangeRate;
use App\Models\Tenant\PaymentMethodType;
use App\Models\Tenant\Person;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Treasury\Models\TreasuryConcept;
use Modules\Treasury\Models\TreasuryMovement;
use Modules\Treasury\Support\Money;

/**
 * Handles manual treasury movements, live ITF/bancarization previews, and atomic voiding (D6, D8, D9, D10).
 *
 * Never calls auth() internally; callers must pass explicit $userId and establishment_id.
 */
class MovementService
{
    public const DESTINATION_TYPES = [
        'bank_account' => BankAccount::class,
        'cash' => Cash::class,
    ];

    public const KIND_LABELS = [
        'income' => 'Ingreso manual',
        'expense' => 'Egreso manual',
        'interest' => 'Intereses ganados',
        'bank_fee' => 'Comisión bancaria',
        'tax_payment' => 'Pago de tributos',
        'adjustment' => 'Ajuste',
        'itf' => 'ITF',
        'transfer_out' => 'Transferencia (salida)',
        'transfer_in' => 'Transferencia (entrada)',
        'detraction_deposit' => 'Depósito de detracción',
        'detraction_release' => 'Liberación de detracción',
    ];

    private $taxRateRepository;
    private $itfCalculator;
    private $bancarizationChecker;
    private $paymentEvidenceService;

    public function __construct(
        TaxRateRepository $taxRateRepository,
        ItfCalculator $itfCalculator,
        BancarizationChecker $bancarizationChecker,
        PaymentEvidenceService $paymentEvidenceService
    ) {
        $this->taxRateRepository = $taxRateRepository;
        $this->itfCalculator = $itfCalculator;
        $this->bancarizationChecker = $bancarizationChecker;
        $this->paymentEvidenceService = $paymentEvidenceService;
    }

    public function createManual(array $payload, int $userId): array
    {
        if ($userId <= 0) {
            throw ValidationException::withMessages([
                'user_id' => ['El usuario que registra el movimiento es obligatorio.'],
            ]);
        }

        $kind = (string) ($payload['kind'] ?? '');
        if (!in_array($kind, TreasuryMovement::MANUAL_KINDS, true)) {
            throw ValidationException::withMessages([
                'kind' => ['El tipo de movimiento manual no es válido.'],
            ]);
        }

        $direction = $this->resolveDirection($kind, $payload['direction'] ?? null);
        $date = $this->validateDate((string) ($payload['date'] ?? ''));
        $amount = $this->validateAmount($payload['amount'] ?? null, 'amount');

        $requestedEstablishmentId = !empty($payload['establishment_id']) ? (int) $payload['establishment_id'] : null;

        $destinationAlias = (string) ($payload['destination_type'] ?? '');
        $destinationClass = $this->resolveDestinationClass($destinationAlias, 'destination_type');
        $destinationId = (int) ($payload['destination_id'] ?? 0);
        if ($destinationId <= 0) {
            throw ValidationException::withMessages([
                'destination_id' => ['Seleccione una cuenta o caja válida.'],
            ]);
        }

        $conceptId = (int) ($payload['treasury_concept_id'] ?? 0);
        if ($conceptId <= 0) {
            throw ValidationException::withMessages([
                'treasury_concept_id' => ['El concepto de tesorería es obligatorio.'],
            ]);
        }

        $description = trim((string) ($payload['description'] ?? ''));
        if ($description === '') {
            throw ValidationException::withMessages([
                'description' => ['La descripción del movimiento es obligatoria.'],
            ]);
        }

        return DB::connection('tenant')->transaction(function () use (
            $payload,
            $userId,
            $kind,
            $direction,
            $date,
            $amount,
            $requestedEstablishmentId,
            $destinationClass,
            $destinationId,
            $conceptId,
            $description
        ) {
            $destination = $this->lockAndValidateDestination($destinationClass, $destinationId, 'destination_id', $date);
            $isBankDestination = ($destination instanceof BankAccount);
            $destinationCurrency = $isBankDestination ? (string) $destination->currency_type_id : 'PEN';

            if ($isBankDestination && !empty($destination->establishment_id)) {
                $accountEstId = (int) $destination->establishment_id;
                if ($requestedEstablishmentId !== null && $requestedEstablishmentId !== $accountEstId) {
                    throw ValidationException::withMessages([
                        'establishment_id' => ['La sede del movimiento debe coincidir con la sede asignada a la cuenta bancaria.'],
                    ]);
                }
                $establishmentId = $accountEstId;
            } else {
                $establishmentId = (int) ($requestedEstablishmentId ?? 0);
            }

            if ($establishmentId <= 0) {
                throw ValidationException::withMessages([
                    'establishment_id' => ['La sede es obligatoria para registrar un movimiento de tesorería.'],
                ]);
            }

            $currency = strtoupper(trim((string) ($payload['currency_type_id'] ?? $destinationCurrency)));
            if ($currency !== $destinationCurrency) {
                throw ValidationException::withMessages([
                    'currency_type_id' => [
                        "La moneda del movimiento ({$currency}) debe coincidir con la moneda de la cuenta ({$destinationCurrency}).",
                    ],
                ]);
            }

            $concept = TreasuryConcept::whereKey($conceptId)->first();
            if (!$concept || !$concept->active) {
                throw ValidationException::withMessages([
                    'treasury_concept_id' => ['El concepto seleccionado no existe o está inactivo.'],
                ]);
            }

            if ($kind === 'income' && $concept->type !== 'income') {
                throw ValidationException::withMessages([
                    'treasury_concept_id' => ['Seleccione un concepto de tipo ingreso.'],
                ]);
            }
            if ($kind === 'expense' && $concept->type !== 'expense') {
                throw ValidationException::withMessages([
                    'treasury_concept_id' => ['Seleccione un concepto de tipo egreso.'],
                ]);
            }

            $fx = $this->resolveExchangeRate($currency, $date, $direction, $payload);
            $amountPen = $currency === 'PEN'
                ? $amount
                : Money::round($amount * $fx['used']);

            $paymentMethodId = !empty($payload['payment_method_type_id'])
                ? trim((string) $payload['payment_method_type_id'])
                : null;
            $paymentMethod = $paymentMethodId ? PaymentMethodType::find($paymentMethodId) : null;
            if ($paymentMethodId !== null && !$paymentMethod) {
                throw ValidationException::withMessages([
                    'payment_method_type_id' => ['El método de pago seleccionado no es válido.'],
                ]);
            }

            $isCashMethod = $paymentMethod
                ? ((bool) $paymentMethod->is_cash || $paymentMethod->id === '01')
                : !$isBankDestination;

            $sunatCode = !empty($payload['sunat_payment_code'])
                ? trim((string) $payload['sunat_payment_code'])
                : ($paymentMethod ? $paymentMethod->sunat_code : null);

            if ($sunatCode !== null && !array_key_exists($sunatCode, BancarizationChecker::SUNAT_PAYMENT_CODES)) {
                throw ValidationException::withMessages([
                    'sunat_payment_code' => ['El código de medio de pago SUNAT no es válido.'],
                ]);
            }

            $bancarization = $this->bancarizationChecker->evaluate(
                $amount,
                $currency,
                $date,
                $isCashMethod,
                $sunatCode,
                !empty($payload['low_compliance_counterparty']),
                $fx['sale']
            );

            $bancarizationException = !empty($payload['bancarization_exception'])
                ? trim((string) $payload['bancarization_exception'])
                : (isset($payload['evidence']['bancarization_exception']) && $payload['evidence']['bancarization_exception'] !== ''
                    ? trim((string) $payload['evidence']['bancarization_exception'])
                    : null);

            if ($bancarizationException !== null && !in_array($bancarizationException, BancarizationChecker::ALLOWED_EXCEPTIONS, true)) {
                throw ValidationException::withMessages([
                    'bancarization_exception' => ['La excepción de bancarización seleccionada no es válida.'],
                ]);
            }

            if ($bancarization['requires_exception_or_bank_method'] && $bancarizationException === null) {
                throw ValidationException::withMessages([
                    'bancarization_exception' => [
                        $bancarization['warning'] ?: 'Debe indicar una excepción del art. 6 de la Ley 28194 o utilizar un medio bancarizado.',
                    ],
                ]);
            }

            if ($sunatCode === null && !empty($bancarization['suggested_sunat_code'])) {
                $sunatCode = $bancarization['suggested_sunat_code'];
            }

            $counterpartyPersonId = !empty($payload['counterparty_person_id'])
                ? (int) $payload['counterparty_person_id']
                : null;
            $counterpartyDocument = $this->nullableTrim($payload['counterparty_document'] ?? null);
            $counterpartyName = $this->nullableTrim($payload['counterparty_name'] ?? null);

            if ($counterpartyPersonId !== null && ($counterpartyDocument === null || $counterpartyName === null)) {
                $person = Person::find($counterpartyPersonId);
                if ($person) {
                    $counterpartyDocument = $counterpartyDocument ?: $person->number;
                    $counterpartyName = $counterpartyName ?: $person->name;
                }
            }

            $operationNumber = $this->nullableTrim($payload['operation_number'] ?? null);

            $movement = TreasuryMovement::create([
                'establishment_id' => $establishmentId,
                'date' => $date,
                'kind' => $kind,
                'direction' => $direction,
                'destination_type' => $destinationClass,
                'destination_id' => $destination->getKey(),
                'currency_type_id' => $currency,
                'amount' => $amount,
                'exchange_rate_purchase' => $fx['purchase'],
                'exchange_rate_sale' => $fx['sale'],
                'exchange_rate_used' => $fx['used'],
                'amount_pen' => $amountPen,
                'payment_method_type_id' => $paymentMethodId,
                'sunat_payment_code' => $sunatCode,
                'operation_number' => $operationNumber,
                'counterparty_person_id' => $counterpartyPersonId,
                'counterparty_document' => $counterpartyDocument,
                'counterparty_name' => $counterpartyName,
                'treasury_concept_id' => $concept->id,
                'description' => $description,
                'state' => TreasuryMovement::STATE_ACTIVE,
                'user_id' => $userId,
            ]);

            $relatedMovements = [];

            // Optional linked ITF line (bank destination only)
            if ($isBankDestination && !empty($payload['include_itf'])) {
                $itfCalc = $this->itfCalculator->calculate(
                    $amount,
                    $date,
                    true,
                    (bool) $destination->itf_exempt,
                    false
                );

                $explicitItf = isset($payload['itf_amount']) && $payload['itf_amount'] !== '' && $payload['itf_amount'] !== null
                    ? $this->validateOptionalAmount($payload['itf_amount'], 'itf_amount')
                    : null;

                $itfAmount = $explicitItf !== null ? $explicitItf : $itfCalc['amount'];

                if ($itfAmount > 0) {
                    $relatedMovements[] = TreasuryMovement::create([
                        'establishment_id' => $establishmentId,
                        'date' => $date,
                        'kind' => 'itf',
                        'direction' => TreasuryMovement::DIRECTION_OUTFLOW,
                        'destination_type' => $destinationClass,
                        'destination_id' => $destination->getKey(),
                        'currency_type_id' => $currency,
                        'amount' => $itfAmount,
                        'exchange_rate_purchase' => $fx['purchase'],
                        'exchange_rate_sale' => $fx['sale'],
                        'exchange_rate_used' => $fx['used'],
                        'amount_pen' => $currency === 'PEN' ? $itfAmount : Money::round($itfAmount * $fx['used']),
                        'payment_method_type_id' => $paymentMethodId,
                        'sunat_payment_code' => $sunatCode,
                        'operation_number' => $operationNumber,
                        'treasury_concept_id' => $concept->id,
                        'description' => 'ITF asociado a movimiento #' . $movement->id,
                        'related_movement_id' => $movement->id,
                        'state' => TreasuryMovement::STATE_ACTIVE,
                        'user_id' => $userId,
                    ]);
                }
            }

            // Optional linked bank fee line (bank destination only)
            $bankFeeRaw = $payload['bank_fee_amount'] ?? null;
            if ($isBankDestination && $bankFeeRaw !== null && $bankFeeRaw !== '' && (float) $bankFeeRaw > 0) {
                $bankFeeAmount = $this->validateAmount($bankFeeRaw, 'bank_fee_amount');
                $bankFeeDescription = $this->nullableTrim($payload['bank_fee_description'] ?? null)
                    ?: ('Comisión bancaria asociada a movimiento #' . $movement->id);

                $relatedMovements[] = TreasuryMovement::create([
                    'establishment_id' => $establishmentId,
                    'date' => $date,
                    'kind' => 'bank_fee',
                    'direction' => TreasuryMovement::DIRECTION_OUTFLOW,
                    'destination_type' => $destinationClass,
                    'destination_id' => $destination->getKey(),
                    'currency_type_id' => $currency,
                    'amount' => $bankFeeAmount,
                    'exchange_rate_purchase' => $fx['purchase'],
                    'exchange_rate_sale' => $fx['sale'],
                    'exchange_rate_used' => $fx['used'],
                    'amount_pen' => $currency === 'PEN' ? $bankFeeAmount : Money::round($bankFeeAmount * $fx['used']),
                    'payment_method_type_id' => $paymentMethodId,
                    'sunat_payment_code' => $sunatCode,
                    'operation_number' => $operationNumber,
                    'treasury_concept_id' => $concept->id,
                    'description' => $bankFeeDescription,
                    'related_movement_id' => $movement->id,
                    'state' => TreasuryMovement::STATE_ACTIVE,
                    'user_id' => $userId,
                ]);
            }

            // Optional bancarization evidence
            $evidence = null;
            $hasExplicitEvidence = !empty($payload['evidence']) && is_array($payload['evidence']);
            $evidenceInput = $hasExplicitEvidence ? $payload['evidence'] : [];
            $effectiveEvidenceCode = !empty($evidenceInput['sunat_payment_code'])
                ? trim((string) $evidenceInput['sunat_payment_code'])
                : $sunatCode;

            $shouldCreateEvidence = $hasExplicitEvidence
                || $bancarizationException !== null
                || ($effectiveEvidenceCode !== null && $operationNumber !== null);

            if ($shouldCreateEvidence && $effectiveEvidenceCode !== null) {
                $evidenceData = [
                    'sunat_payment_code' => $effectiveEvidenceCode,
                    'operation_number' => $evidenceInput['operation_number'] ?? $operationNumber,
                    'bank_id' => $evidenceInput['bank_id'] ?? ($isBankDestination ? $destination->bank_id : null),
                    'bank_account_id' => $evidenceInput['bank_account_id'] ?? ($isBankDestination ? $destination->id : null),
                    'operation_date' => $evidenceInput['operation_date'] ?? $date,
                    'payer_or_beneficiary_document' => $evidenceInput['payer_or_beneficiary_document'] ?? $counterpartyDocument,
                    'payer_or_beneficiary_name' => $evidenceInput['payer_or_beneficiary_name'] ?? $counterpartyName,
                    'third_party_payment' => !empty($evidenceInput['third_party_payment']),
                    'third_party_reported_to_sunat' => !empty($evidenceInput['third_party_reported_to_sunat']),
                    'bancarization_exception' => $evidenceInput['bancarization_exception'] ?? $bancarizationException,
                    'low_compliance_counterparty' => !empty($payload['low_compliance_counterparty']),
                ];

                $evidence = $this->paymentEvidenceService->upsertForModel($movement, $evidenceData);
            }

            return [
                'movement' => $movement,
                'related_movements' => $relatedMovements,
                'evidence' => $evidence,
                'bancarization' => $bancarization,
            ];
        });
    }

    /**
     * Atomically voids a movement (or full transfer group) and all active linked ITF/bank_fee children (D6, D10).
     */
    public function void(int $movementId, string $reason, int $userId): array
    {
        $cleanReason = trim($reason);
        if (mb_strlen($cleanReason) < 3) {
            throw ValidationException::withMessages([
                'void_reason' => ['El motivo de anulación es obligatorio (mínimo 3 caracteres).'],
            ]);
        }

        if ($userId <= 0) {
            throw ValidationException::withMessages([
                'user_id' => ['El usuario que anula el movimiento es obligatorio.'],
            ]);
        }

        return DB::connection('tenant')->transaction(function () use ($movementId, $cleanReason, $userId) {
            $target = TreasuryMovement::whereKey($movementId)->lockForUpdate()->firstOrFail();

            if ($target->state === TreasuryMovement::STATE_VOIDED) {
                throw ValidationException::withMessages([
                    'movement' => ['El movimiento ya se encuentra anulado.'],
                ]);
            }

            $parentMovements = $target->transfer_group !== null
                ? TreasuryMovement::where('transfer_group', $target->transfer_group)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                : collect([$target]);

            $parentIds = $parentMovements->pluck('id')->all();

            $childMovements = TreasuryMovement::whereIn('related_movement_id', $parentIds)
                ->where('state', TreasuryMovement::STATE_ACTIVE)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $toVoid = $parentMovements
                ->merge($childMovements)
                ->unique('id')
                ->filter(function (TreasuryMovement $item) {
                    return $item->state === TreasuryMovement::STATE_ACTIVE;
                })
                ->values();

            // Lock involved destinations in deterministic order to avoid deadlocks with concurrent operations.
            $destinations = [];
            foreach ($toVoid as $item) {
                $key = $item->destination_type . ':' . $item->destination_id;
                $destinations[$key] = [$item->destination_type, (int) $item->destination_id];
            }
            ksort($destinations);

            foreach ($destinations as [$destClass, $destId]) {
                if (class_exists($destClass)) {
                    $lockedDest = $destClass::whereKey($destId)->lockForUpdate()->first();
                    if ($lockedDest instanceof Cash && (int) $lockedDest->state !== 1) {
                        throw ValidationException::withMessages([
                            'movement' => ['No se puede anular un movimiento vinculado a una caja POS cerrada.'],
                        ]);
                    }
                }
            }

            $now = now();
            foreach ($toVoid as $item) {
                $item->state = TreasuryMovement::STATE_VOIDED;
                $item->voided_at = $now;
                $item->voided_by = $userId;
                $item->void_reason = $cleanReason;
                $item->save();
            }

            return [
                'voided_movements' => $toVoid->all(),
                'count' => $toVoid->count(),
                'transfer_group' => $target->transfer_group,
            ];
        });
    }

    /**
     * Live preview for UI forms (evaluates ITF suggestion + Ley 28194 bancarization status).
     */
    public function preview(array $payload): array
    {
        $amount = max(0.0, Money::round((float) ($payload['amount'] ?? 0)));
        $date = !empty($payload['date']) ? (string) $payload['date'] : date('Y-m-d');
        $destinationType = (string) ($payload['destination_type'] ?? 'bank_account');
        $destinationId = !empty($payload['destination_id']) ? (int) $payload['destination_id'] : null;

        $isBankDestination = ($destinationType === 'bank_account');
        $isItfExempt = false;
        $currency = strtoupper(trim((string) ($payload['currency_type_id'] ?? 'PEN')));

        if ($isBankDestination && $destinationId) {
            $account = BankAccount::find($destinationId);
            if ($account) {
                $isItfExempt = (bool) $account->itf_exempt;
                $currency = (string) $account->currency_type_id;
            }
        } elseif (!$isBankDestination) {
            $currency = 'PEN';
        }

        $paymentMethod = !empty($payload['payment_method_type_id'])
            ? PaymentMethodType::find($payload['payment_method_type_id'])
            : null;
        $isCashMethod = $paymentMethod
            ? ((bool) $paymentMethod->is_cash || $paymentMethod->id === '01')
            : !$isBankDestination;

        $sunatCode = !empty($payload['sunat_payment_code'])
            ? trim((string) $payload['sunat_payment_code'])
            : ($paymentMethod ? $paymentMethod->sunat_code : null);

        $exchangeRateSale = !empty($payload['exchange_rate_sale'])
            ? (float) $payload['exchange_rate_sale']
            : null;

        if ($exchangeRateSale === null && $currency !== 'PEN' && $currency !== 'USD') {
            $fx = $this->lookupExchangeRateOnOrBefore($date);
            if ($fx && $fx->sale > 0) {
                $exchangeRateSale = (float) $fx->sale;
            }
        }

        $itf = $this->itfCalculator->calculate(
            $amount,
            $date,
            $isBankDestination,
            $isItfExempt,
            !empty($payload['same_holder'])
        );

        $bancarization = $this->bancarizationChecker->evaluate(
            $amount,
            $currency,
            $date,
            $isCashMethod,
            $sunatCode,
            !empty($payload['low_compliance_counterparty']),
            $exchangeRateSale
        );

        return [
            'itf' => $itf,
            'bancarization' => $bancarization,
            'currency_type_id' => $currency,
            'is_itf_exempt' => $isItfExempt,
        ];
    }

    public function lookupExchangeRateOnOrBefore(string $date): ?ExchangeRate
    {
        if (!Model::getConnectionResolver()) {
            return null;
        }

        try {
            if (!DB::connection('tenant')->getSchemaBuilder()->hasTable('exchange_rates')) {
                return null;
            }

            $exact = ExchangeRate::where('date', $date)->first();
            if ($exact) {
                return $exact;
            }

            return ExchangeRate::where('date', '<=', $date)
                ->orderBy('date', 'desc')
                ->first();
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function resolveDirection(string $kind, $explicitDirection): int
    {
        if (in_array($kind, TreasuryMovement::INFLOW_KINDS, true)) {
            return TreasuryMovement::DIRECTION_INFLOW;
        }

        if (in_array($kind, TreasuryMovement::OUTFLOW_KINDS, true)) {
            return TreasuryMovement::DIRECTION_OUTFLOW;
        }

        $parsed = (int) $explicitDirection;
        if (!in_array($parsed, [TreasuryMovement::DIRECTION_INFLOW, TreasuryMovement::DIRECTION_OUTFLOW], true)) {
            throw ValidationException::withMessages([
                'direction' => ['Para un ajuste debe indicar si es entrada (+1) o salida (-1).'],
            ]);
        }

        return $parsed;
    }

    private function validateDate(string $date): string
    {
        $trimmed = trim($date);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $trimmed)) {
            throw ValidationException::withMessages([
                'date' => ['La fecha del movimiento debe tener el formato YYYY-MM-DD.'],
            ]);
        }

        $allowFuture = (bool) config('treasury.allow_future_dates', false);
        if (!$allowFuture && $trimmed > date('Y-m-d')) {
            throw ValidationException::withMessages([
                'date' => ['No se permiten movimientos con fecha futura.'],
            ]);
        }

        return $trimmed;
    }

    private function validateAmount($rawAmount, string $field): float
    {
        if (!is_numeric($rawAmount)) {
            throw ValidationException::withMessages([
                $field => ['El monto debe ser un valor numérico válido.'],
            ]);
        }

        $rounded = Money::round((float) $rawAmount);
        if ($rounded < Money::MIN_AMOUNT || $rounded > Money::MAX_AMOUNT) {
            throw ValidationException::withMessages([
                $field => ['El monto debe estar entre 0.01 y 9,999,999,999.99.'],
            ]);
        }

        return $rounded;
    }

    private function validateOptionalAmount($rawAmount, string $field): float
    {
        if (!is_numeric($rawAmount)) {
            throw ValidationException::withMessages([
                $field => ['El monto debe ser un valor numérico válido.'],
            ]);
        }

        $rounded = Money::round((float) $rawAmount);
        if ($rounded < 0 || $rounded > Money::MAX_AMOUNT) {
            throw ValidationException::withMessages([
                $field => ['El monto debe estar entre 0.00 y 9,999,999,999.99.'],
            ]);
        }

        return $rounded;
    }

    private function resolveDestinationClass(string $alias, string $field): string
    {
        $normalized = strtolower(trim($alias));
        if (!isset(self::DESTINATION_TYPES[$normalized])) {
            throw ValidationException::withMessages([
                $field => ['Tipo de destino no válido. Seleccione cuenta bancaria o caja.'],
            ]);
        }

        return self::DESTINATION_TYPES[$normalized];
    }

    private function lockAndValidateDestination(string $class, int $id, string $field, string $date): Model
    {
        $model = $class::whereKey($id)->lockForUpdate()->first();
        if (!$model) {
            throw ValidationException::withMessages([
                $field => ['La cuenta o caja seleccionada no existe.'],
            ]);
        }

        if ($model instanceof BankAccount && (int) $model->status !== 1) {
            throw ValidationException::withMessages([
                $field => ['La cuenta bancaria seleccionada está inactiva.'],
            ]);
        }

        if ($model instanceof Cash) {
            if ((int) $model->state !== 1) {
                throw ValidationException::withMessages([
                    $field => ['La caja POS seleccionada está cerrada. Debe estar abierta para registrar movimientos.'],
                ]);
            }
            if (!empty($model->date_opening) && $date < (string) $model->date_opening) {
                throw ValidationException::withMessages([
                    'date' => ['La fecha del movimiento no puede ser anterior a la apertura de la caja POS.'],
                ]);
            }
        }

        return $model;
    }

    private function resolveExchangeRate(string $currency, string $date, int $direction, array $payload): array
    {
        if ($currency === 'PEN') {
            return [
                'purchase' => null,
                'sale' => null,
                'used' => 1.0,
            ];
        }

        $fx = $this->lookupExchangeRateOnOrBefore($date);
        $purchase = isset($payload['exchange_rate_purchase']) && is_numeric($payload['exchange_rate_purchase']) && (float) $payload['exchange_rate_purchase'] > 0
            ? round((float) $payload['exchange_rate_purchase'], 4)
            : ($fx && $fx->purchase > 0 ? round((float) $fx->purchase, 4) : null);

        $sale = isset($payload['exchange_rate_sale']) && is_numeric($payload['exchange_rate_sale']) && (float) $payload['exchange_rate_sale'] > 0
            ? round((float) $payload['exchange_rate_sale'], 4)
            : ($fx && $fx->sale > 0 ? round((float) $fx->sale, 4) : null);

        $used = null;
        if (isset($payload['exchange_rate_used']) && is_numeric($payload['exchange_rate_used']) && (float) $payload['exchange_rate_used'] > 0) {
            $used = round((float) $payload['exchange_rate_used'], 4);
        } elseif ($direction === TreasuryMovement::DIRECTION_INFLOW) {
            $used = $purchase ?? $sale;
        } else {
            $used = $sale ?? $purchase;
        }

        if ($used === null || $used <= 0) {
            throw ValidationException::withMessages([
                'exchange_rate_used' => ['Debe indicar un tipo de cambio válido para movimientos en moneda extranjera.'],
            ]);
        }

        return [
            'purchase' => $purchase ?? $used,
            'sale' => $sale ?? $used,
            'used' => $used,
        ];
    }

    private function nullableTrim($value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
