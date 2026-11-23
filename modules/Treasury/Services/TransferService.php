<?php

namespace Modules\Treasury\Services;

use App\Models\Tenant\BankAccount;
use App\Models\Tenant\Cash;
use App\Models\Tenant\ExchangeRate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Treasury\Models\TreasuryMovement;
use Modules\Treasury\Support\Money;

/**
 * Atomic account/cash transfers with deterministic lockForUpdate ordering (D6, D8, D10).
 *
 * Supports bank<->bank, cash->bank, bank->cash, and distinct open cash->cash transfers,
 * both same-currency and cross-currency.
 */
class TransferService
{
    public const DESTINATION_TYPES = [
        'bank_account' => BankAccount::class,
        'cash' => Cash::class,
    ];

    private $itfCalculator;
    private $paymentEvidenceService;

    public function __construct(
        ItfCalculator $itfCalculator,
        PaymentEvidenceService $paymentEvidenceService
    ) {
        $this->itfCalculator = $itfCalculator;
        $this->paymentEvidenceService = $paymentEvidenceService;
    }

    public function create(array $payload, int $userId): array
    {
        if ($userId <= 0) {
            throw ValidationException::withMessages([
                'user_id' => ['El usuario que registra la transferencia es obligatorio.'],
            ]);
        }

        $establishmentId = (int) ($payload['establishment_id'] ?? 0);
        if ($establishmentId <= 0) {
            throw ValidationException::withMessages([
                'establishment_id' => ['La sede es obligatoria para registrar una transferencia.'],
            ]);
        }

        $date = $this->validateDate((string) ($payload['date'] ?? ''));
        $amount = $this->validateAmount($payload['amount'] ?? null, 'amount');

        $originClass = $this->resolveDestinationClass((string) ($payload['origin_type'] ?? ''), 'origin_type');
        $targetClass = $this->resolveDestinationClass((string) ($payload['target_type'] ?? ''), 'target_type');
        $originId = (int) ($payload['origin_id'] ?? 0);
        $targetId = (int) ($payload['target_id'] ?? 0);

        if ($originId <= 0) {
            throw ValidationException::withMessages([
                'origin_id' => ['Seleccione una cuenta o caja de origen válida.'],
            ]);
        }
        if ($targetId <= 0) {
            throw ValidationException::withMessages([
                'target_id' => ['Seleccione una cuenta o caja de destino válida.'],
            ]);
        }
        if ($originClass === $targetClass && $originId === $targetId) {
            throw ValidationException::withMessages([
                'target_id' => ['La cuenta o caja de destino debe ser distinta a la de origen.'],
            ]);
        }

        $description = trim((string) ($payload['description'] ?? ''));
        if ($description === '') {
            throw ValidationException::withMessages([
                'description' => ['La descripción de la transferencia es obligatoria.'],
            ]);
        }

        return DB::connection('tenant')->transaction(function () use (
            $payload,
            $userId,
            $establishmentId,
            $date,
            $amount,
            $originClass,
            $originId,
            $targetClass,
            $targetId,
            $description
        ) {
            // Lock both destinations in deterministic sorted order to prevent deadlocks (Decision D10).
            $originKey = "{$originClass}:{$originId}";
            $targetKey = "{$targetClass}:{$targetId}";

            $orderedSpecs = [
                $originKey => ['role' => 'origin', 'class' => $originClass, 'id' => $originId, 'field' => 'origin_id'],
                $targetKey => ['role' => 'target', 'class' => $targetClass, 'id' => $targetId, 'field' => 'target_id'],
            ];
            ksort($orderedSpecs);

            $locked = [];
            foreach ($orderedSpecs as $spec) {
                $locked[$spec['role']] = $this->lockAndValidateDestination(
                    $spec['class'],
                    $spec['id'],
                    $spec['field'],
                    $date
                );
            }

            /** @var BankAccount|Cash $origin */
            $origin = $locked['origin'];
            /** @var BankAccount|Cash $target */
            $target = $locked['target'];

            $originCurrency = ($origin instanceof BankAccount) ? (string) $origin->currency_type_id : 'PEN';
            $targetCurrency = ($target instanceof BankAccount) ? (string) $target->currency_type_id : 'PEN';

            $fxResult = $this->resolveTransferAmounts(
                $amount,
                $originCurrency,
                $targetCurrency,
                $date,
                $payload
            );

            $transferGroup = (string) Str::uuid();
            $operationNumber = $this->nullableTrim($payload['operation_number'] ?? null);
            $paymentMethodId = !empty($payload['payment_method_type_id'])
                ? trim((string) $payload['payment_method_type_id'])
                : (($origin instanceof BankAccount || $target instanceof BankAccount) ? '04' : '01');
            $sunatCode = !empty($payload['sunat_payment_code'])
                ? trim((string) $payload['sunat_payment_code'])
                : (($origin instanceof BankAccount && $target instanceof BankAccount)
                    ? '003'
                    : (($origin instanceof Cash && $target instanceof BankAccount) ? '001' : '008'));

            if (!array_key_exists($sunatCode, BancarizationChecker::SUNAT_PAYMENT_CODES)) {
                throw ValidationException::withMessages([
                    'sunat_payment_code' => ['El código de medio de pago SUNAT no es válido.'],
                ]);
            }

            $outEstablishmentId = ($origin instanceof BankAccount && !empty($origin->establishment_id))
                ? (int) $origin->establishment_id
                : $establishmentId;
            $inEstablishmentId = ($target instanceof BankAccount && !empty($target->establishment_id))
                ? (int) $target->establishment_id
                : $establishmentId;

            $out = TreasuryMovement::create([
                'establishment_id' => $outEstablishmentId,
                'date' => $date,
                'kind' => 'transfer_out',
                'direction' => TreasuryMovement::DIRECTION_OUTFLOW,
                'destination_type' => $originClass,
                'destination_id' => $origin->getKey(),
                'currency_type_id' => $originCurrency,
                'amount' => $fxResult['out_amount'],
                'exchange_rate_purchase' => $fxResult['purchase'],
                'exchange_rate_sale' => $fxResult['sale'],
                'exchange_rate_used' => $fxResult['used'],
                'amount_pen' => $fxResult['out_amount_pen'],
                'payment_method_type_id' => $paymentMethodId,
                'sunat_payment_code' => $sunatCode,
                'operation_number' => $operationNumber,
                'description' => $description,
                'transfer_group' => $transferGroup,
                'state' => TreasuryMovement::STATE_ACTIVE,
                'user_id' => $userId,
            ]);

            $in = TreasuryMovement::create([
                'establishment_id' => $inEstablishmentId,
                'date' => $date,
                'kind' => 'transfer_in',
                'direction' => TreasuryMovement::DIRECTION_INFLOW,
                'destination_type' => $targetClass,
                'destination_id' => $target->getKey(),
                'currency_type_id' => $targetCurrency,
                'amount' => $fxResult['in_amount'],
                'exchange_rate_purchase' => $fxResult['purchase'],
                'exchange_rate_sale' => $fxResult['sale'],
                'exchange_rate_used' => $fxResult['used'],
                'amount_pen' => $fxResult['in_amount_pen'],
                'payment_method_type_id' => $paymentMethodId,
                'sunat_payment_code' => $sunatCode,
                'operation_number' => $operationNumber,
                'description' => $description,
                'transfer_group' => $transferGroup,
                'state' => TreasuryMovement::STATE_ACTIVE,
                'user_id' => $userId,
            ]);

            $relatedMovements = [];

            // Determine bank leg for optional ITF / bank fee (prefer origin if bank, else target if bank)
            $bankLeg = null;
            $bankLegAmount = 0.0;
            $bankLegCurrency = 'PEN';
            $bankLegEstablishmentId = $establishmentId;
            if ($origin instanceof BankAccount) {
                $bankLeg = $origin;
                $bankLegAmount = $fxResult['out_amount'];
                $bankLegCurrency = $originCurrency;
                $bankLegEstablishmentId = $outEstablishmentId;
            } elseif ($target instanceof BankAccount) {
                $bankLeg = $target;
                $bankLegAmount = $fxResult['in_amount'];
                $bankLegCurrency = $targetCurrency;
                $bankLegEstablishmentId = $inEstablishmentId;
            }

            if ($bankLeg !== null && !empty($payload['include_itf'])) {
                $sameHolder = !empty($payload['same_holder']);
                $itfCalc = $this->itfCalculator->calculate(
                    $bankLegAmount,
                    $date,
                    true,
                    (bool) $bankLeg->itf_exempt,
                    $sameHolder
                );

                $explicitItf = isset($payload['itf_amount']) && $payload['itf_amount'] !== '' && $payload['itf_amount'] !== null
                    ? $this->validateOptionalAmount($payload['itf_amount'], 'itf_amount')
                    : null;

                $itfAmount = $explicitItf !== null ? $explicitItf : $itfCalc['amount'];

                if ($itfAmount > 0) {
                    $itfAmountPen = $bankLegCurrency === 'PEN'
                        ? $itfAmount
                        : Money::round($itfAmount * $fxResult['used']);

                    $relatedMovements[] = TreasuryMovement::create([
                        'establishment_id' => $bankLegEstablishmentId,
                        'date' => $date,
                        'kind' => 'itf',
                        'direction' => TreasuryMovement::DIRECTION_OUTFLOW,
                        'destination_type' => BankAccount::class,
                        'destination_id' => $bankLeg->getKey(),
                        'currency_type_id' => $bankLegCurrency,
                        'amount' => $itfAmount,
                        'exchange_rate_purchase' => $fxResult['purchase'],
                        'exchange_rate_sale' => $fxResult['sale'],
                        'exchange_rate_used' => $fxResult['used'],
                        'amount_pen' => $itfAmountPen,
                        'payment_method_type_id' => $paymentMethodId,
                        'sunat_payment_code' => $sunatCode,
                        'operation_number' => $operationNumber,
                        'description' => 'ITF asociado a transferencia ' . substr($transferGroup, 0, 8),
                        'related_movement_id' => $out->id,
                        'state' => TreasuryMovement::STATE_ACTIVE,
                        'user_id' => $userId,
                    ]);
                }
            }

            $bankFeeRaw = $payload['bank_fee_amount'] ?? null;
            if ($bankLeg !== null && $bankFeeRaw !== null && $bankFeeRaw !== '' && (float) $bankFeeRaw > 0) {
                $bankFeeAmount = $this->validateAmount($bankFeeRaw, 'bank_fee_amount');
                $feeAmountPen = $bankLegCurrency === 'PEN'
                    ? $bankFeeAmount
                    : Money::round($bankFeeAmount * $fxResult['used']);

                $relatedMovements[] = TreasuryMovement::create([
                    'establishment_id' => $bankLegEstablishmentId,
                    'date' => $date,
                    'kind' => 'bank_fee',
                    'direction' => TreasuryMovement::DIRECTION_OUTFLOW,
                    'destination_type' => BankAccount::class,
                    'destination_id' => $bankLeg->getKey(),
                    'currency_type_id' => $bankLegCurrency,
                    'amount' => $bankFeeAmount,
                    'exchange_rate_purchase' => $fxResult['purchase'],
                    'exchange_rate_sale' => $fxResult['sale'],
                    'exchange_rate_used' => $fxResult['used'],
                    'amount_pen' => $feeAmountPen,
                    'payment_method_type_id' => $paymentMethodId,
                    'sunat_payment_code' => $sunatCode,
                    'operation_number' => $operationNumber,
                    'description' => $this->nullableTrim($payload['bank_fee_description'] ?? null)
                        ?: ('Comisión bancaria asociada a transferencia ' . substr($transferGroup, 0, 8)),
                    'related_movement_id' => $out->id,
                    'state' => TreasuryMovement::STATE_ACTIVE,
                    'user_id' => $userId,
                ]);
            }

            $evidence = null;
            if (!empty($payload['evidence']) && is_array($payload['evidence'])) {
                $ev = $payload['evidence'];
                $evidence = $this->paymentEvidenceService->upsertForModel($out, [
                    'sunat_payment_code' => $ev['sunat_payment_code'] ?? $sunatCode,
                    'operation_number' => $ev['operation_number'] ?? $operationNumber,
                    'bank_id' => $ev['bank_id'] ?? ($bankLeg ? $bankLeg->bank_id : null),
                    'bank_account_id' => $ev['bank_account_id'] ?? ($bankLeg ? $bankLeg->id : null),
                    'operation_date' => $ev['operation_date'] ?? $date,
                    'payer_or_beneficiary_document' => $ev['payer_or_beneficiary_document'] ?? null,
                    'payer_or_beneficiary_name' => $ev['payer_or_beneficiary_name'] ?? null,
                    'third_party_payment' => !empty($ev['third_party_payment']),
                    'third_party_reported_to_sunat' => !empty($ev['third_party_reported_to_sunat']),
                    'bancarization_exception' => $ev['bancarization_exception'] ?? null,
                ]);
            }

            return [
                'transfer_group' => $transferGroup,
                'out' => $out,
                'in' => $in,
                'related_movements' => $relatedMovements,
                'evidence' => $evidence,
            ];
        });
    }

    private function resolveTransferAmounts(
        float $outAmount,
        string $originCurrency,
        string $targetCurrency,
        string $date,
        array $payload
    ): array {
        $fx = $this->lookupExchangeRateOnOrBefore($date);
        $purchase = isset($payload['exchange_rate_purchase']) && is_numeric($payload['exchange_rate_purchase']) && (float) $payload['exchange_rate_purchase'] > 0
            ? round((float) $payload['exchange_rate_purchase'], 4)
            : ($fx && $fx->purchase > 0 ? round((float) $fx->purchase, 4) : null);

        $sale = isset($payload['exchange_rate_sale']) && is_numeric($payload['exchange_rate_sale']) && (float) $payload['exchange_rate_sale'] > 0
            ? round((float) $payload['exchange_rate_sale'], 4)
            : ($fx && $fx->sale > 0 ? round((float) $fx->sale, 4) : null);

        $explicitRate = isset($payload['exchange_rate_used']) && is_numeric($payload['exchange_rate_used']) && (float) $payload['exchange_rate_used'] > 0
            ? round((float) $payload['exchange_rate_used'], 4)
            : null;

        // Same-currency transfer
        if ($originCurrency === $targetCurrency) {
            if ($originCurrency === 'PEN') {
                return [
                    'out_amount' => $outAmount,
                    'in_amount' => $outAmount,
                    'out_amount_pen' => $outAmount,
                    'in_amount_pen' => $outAmount,
                    'purchase' => null,
                    'sale' => null,
                    'used' => 1.0,
                ];
            }

            $rateUsed = $explicitRate ?? $purchase ?? $sale;
            if ($rateUsed === null || $rateUsed <= 0) {
                throw ValidationException::withMessages([
                    'exchange_rate_used' => ['Debe indicar un tipo de cambio válido para transferencias en moneda extranjera.'],
                ]);
            }

            $amountPen = Money::round($outAmount * $rateUsed);

            return [
                'out_amount' => $outAmount,
                'in_amount' => $outAmount,
                'out_amount_pen' => $amountPen,
                'in_amount_pen' => $amountPen,
                'purchase' => $purchase ?? $rateUsed,
                'sale' => $sale ?? $rateUsed,
                'used' => $rateUsed,
            ];
        }

        // Cross-currency transfer
        $explicitTarget = isset($payload['target_amount']) && $payload['target_amount'] !== '' && $payload['target_amount'] !== null
            ? $this->validateAmount($payload['target_amount'], 'target_amount')
            : null;

        $rateUsed = $explicitRate;
        if ($rateUsed === null && $explicitTarget !== null) {
            if ($originCurrency === 'PEN') {
                $rateUsed = round($outAmount / $explicitTarget, 4);
            } else {
                $rateUsed = round($explicitTarget / $outAmount, 4);
            }
        }
        if ($rateUsed === null) {
            $rateUsed = ($originCurrency === 'PEN') ? ($sale ?? $purchase) : ($purchase ?? $sale);
        }

        if ($rateUsed === null || $rateUsed <= 0) {
            throw ValidationException::withMessages([
                'exchange_rate_used' => ['Debe indicar un tipo de cambio válido para transferencias entre monedas distintas.'],
            ]);
        }

        if ($explicitTarget !== null) {
            $inAmount = $explicitTarget;
        } elseif ($originCurrency === 'PEN') {
            $inAmount = Money::round($outAmount / $rateUsed);
        } else {
            $inAmount = Money::round($outAmount * $rateUsed);
        }

        if ($inAmount < Money::MIN_AMOUNT) {
            throw ValidationException::withMessages([
                'target_amount' => ['El monto convertido en la moneda de destino debe ser mayor o igual a 0.01.'],
            ]);
        }

        if ($originCurrency === 'PEN') {
            $outAmountPen = $outAmount;
            $inAmountPen = Money::round($inAmount * $rateUsed);
        } elseif ($targetCurrency === 'PEN') {
            $outAmountPen = Money::round($outAmount * $rateUsed);
            $inAmountPen = $inAmount;
        } else {
            $outAmountPen = Money::round($outAmount * $rateUsed);
            $inAmountPen = Money::round($inAmount * $rateUsed);
        }

        return [
            'out_amount' => $outAmount,
            'in_amount' => $inAmount,
            'out_amount_pen' => $outAmountPen,
            'in_amount_pen' => $inAmountPen,
            'purchase' => $purchase ?? $rateUsed,
            'sale' => $sale ?? $rateUsed,
            'used' => $rateUsed,
        ];
    }

    private function lookupExchangeRateOnOrBefore(string $date): ?ExchangeRate
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

    private function validateDate(string $date): string
    {
        $trimmed = trim($date);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $trimmed)) {
            throw ValidationException::withMessages([
                'date' => ['La fecha de la transferencia debe tener el formato YYYY-MM-DD.'],
            ]);
        }

        $allowFuture = (bool) config('treasury.allow_future_dates', false);
        if (!$allowFuture && $trimmed > date('Y-m-d')) {
            throw ValidationException::withMessages([
                'date' => ['No se permiten transferencias con fecha futura.'],
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
                $field => ['Tipo de cuenta no válido. Seleccione cuenta bancaria o caja.'],
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

        // SPOT account: its funds can only pay SUNAT taxes, and it is only credited by detraction deposits.
        // Releases (Formulario 1697) are registered from Tesorería > Detracciones, never as a generic transfer.
        if ($model instanceof BankAccount && ($model->account_type ?? null) === 'detraction_bn') {
            throw ValidationException::withMessages([
                $field => ['La cuenta de detracciones del Banco de la Nación no admite transferencias. Use Detracciones para registrar liberaciones de fondos.'],
            ]);
        }

        if ($model instanceof Cash) {
            if ((int) $model->state !== 1) {
                throw ValidationException::withMessages([
                    $field => ['La caja POS seleccionada está cerrada. Debe estar abierta para realizar transferencias.'],
                ]);
            }
            if (!empty($model->date_opening) && $date < (string) $model->date_opening) {
                throw ValidationException::withMessages([
                    'date' => ['La fecha de la transferencia no puede ser anterior a la apertura de la caja POS.'],
                ]);
            }
        }

        return $model;
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
