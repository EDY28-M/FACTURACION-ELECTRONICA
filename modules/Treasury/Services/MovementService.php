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
use Modules\Treasury\Models\DetractionRecord;
use Modules\Treasury\Models\TreasuryConcept;
use Modules\Treasury\Models\TreasuryInstrument;
use Modules\Treasury\Models\TreasuryMovement;
use Modules\Treasury\Models\TreasuryWithholding;
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

    /**
     * @param array<int, string> $extraKinds additional kinds accepted by this call only (the bank reconciliation
     *                                       creates `itf` lines from statement rows; the public manual form cannot)
     */
    public function createManual(array $payload, int $userId, array $extraKinds = []): array
    {
        if ($userId <= 0) {
            throw ValidationException::withMessages([
                'user_id' => ['El usuario que registra el movimiento es obligatorio.'],
            ]);
        }

        $kind = (string) ($payload['kind'] ?? '');
        if (!in_array($kind, array_merge(TreasuryMovement::MANUAL_KINDS, $extraKinds), true)) {
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

            // SPOT account: funds can only be used to pay SUNAT taxes (TUO DL 940). Deposits and releases are
            // registered by the detraction flows, never as a manual movement.
            if ($isBankDestination && ($destination->account_type ?? null) === 'detraction_bn' && $kind !== 'tax_payment') {
                throw ValidationException::withMessages([
                    'destination_id' => ['La cuenta de detracciones del Banco de la Nación solo puede usarse para pagar tributos administrados por SUNAT.'],
                ]);
            }

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

            $operationNumber = $this->operationNumber($payload['operation_number'] ?? null);

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
    public function void(int $movementId, string $reason, int $userId, bool $allowDetractionLinked = false, bool $allowInstrumentLinked = false): array
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

        return DB::connection('tenant')->transaction(function () use ($movementId, $cleanReason, $userId, $allowDetractionLinked, $allowInstrumentLinked) {
            // Global lock order (shared with the bank reconciliation): destination account/cash FIRST (sorted), then
            // the movement rows. Learn the destinations with an unlocked read, lock them, then lock the movements
            // and verify the destinations did not change in between.
            $preDestinations = $this->collectVoidDestinations($movementId);
            foreach ($preDestinations as [$destClass, $destId]) {
                if (class_exists($destClass)) {
                    $destClass::whereKey($destId)->lockForUpdate()->first();
                }
            }

            $target = TreasuryMovement::whereKey($movementId)->lockForUpdate()->firstOrFail();

            if ($target->state === TreasuryMovement::STATE_VOIDED) {
                throw ValidationException::withMessages([
                    'movement' => ['El movimiento ya se encuentra anulado.'],
                ]);
            }

            // Deposit movements are owned by a detraction record: voiding them directly would leave the record
            // marked as deposited. They must be reverted from the detraction flow (which passes the flag).
            if (!$allowDetractionLinked && $target->source_type === DetractionRecord::class) {
                throw ValidationException::withMessages([
                    'movement' => ['Este movimiento pertenece al control de detracciones. Revierta el depósito desde Detracciones.'],
                ]);
            }

            // Cheque / bill movements are owned by their instrument: the state and the money must change together,
            // so they can only be voided from the instrument flow (which passes the flag).
            if (!$allowInstrumentLinked && $target->source_type === TreasuryInstrument::class) {
                throw ValidationException::withMessages([
                    'movement' => ['Este movimiento pertenece a un cheque o letra. Revierta la operación desde Cheques y letras.'],
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

            // The destinations were locked up front (sorted). If the set changed between the unlocked read and the
            // row locks (e.g. a child was created concurrently), fail cleanly instead of locking out of order.
            $destinations = [];
            foreach ($toVoid as $item) {
                $key = $item->destination_type . ':' . $item->destination_id;
                $destinations[$key] = [$item->destination_type, (int) $item->destination_id];
            }
            ksort($destinations);

            if (array_keys($destinations) !== array_keys($preDestinations)) {
                throw ValidationException::withMessages([
                    'movement' => ['El movimiento cambió mientras se procesaba la anulación. Intente nuevamente.'],
                ]);
            }

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

            // Bank reconciliation guard: a movement matched to a statement line cannot disappear from the books.
            // Reconciled (closed statement) movements require reopening the reconciliation; matched ones, unmatching.
            foreach ($toVoid as $item) {
                if ($item->reconciled_at !== null) {
                    throw ValidationException::withMessages([
                        'movement' => ['Este movimiento pertenece a una conciliación bancaria cerrada. Reabra la conciliación antes de anularlo.'],
                    ]);
                }
                if ($item->bank_statement_line_id !== null) {
                    throw ValidationException::withMessages([
                        'movement' => ['Este movimiento está emparejado con un extracto bancario. Desvincúlelo en Conciliación bancaria antes de anularlo.'],
                    ]);
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

            $this->releaseWithholdingSettlements($toVoid->pluck('id')->all());

            return [
                'voided_movements' => $toVoid->all(),
                'count' => $toVoid->count(),
                'transfer_group' => $target->transfer_group,
            ];
        });
    }

    /**
     * Unlocked read of the destinations (sorted by key) touched by voiding a movement: the active movements of its
     * transfer group plus their active ITF/bank_fee children. Empty when the movement does not exist.
     *
     * @return array<string, array{0: string, 1: int}>
     */
    protected function collectVoidDestinations(int $movementId): array
    {
        $target = TreasuryMovement::whereKey($movementId)->first();
        if (!$target) {
            return [];
        }

        $parents = $target->transfer_group !== null
            ? TreasuryMovement::where('transfer_group', $target->transfer_group)->get()
            : collect([$target]);

        $children = TreasuryMovement::whereIn('related_movement_id', $parents->pluck('id')->all())
            ->where('state', TreasuryMovement::STATE_ACTIVE)
            ->get();

        $destinations = [];
        foreach ($parents->merge($children)->unique('id') as $item) {
            if ($item->state !== TreasuryMovement::STATE_ACTIVE) {
                continue;
            }
            $destinations[$item->destination_type . ':' . $item->destination_id] = [$item->destination_type, (int) $item->destination_id];
        }
        ksort($destinations);

        return $destinations;
    }

    /**
     * A voided `tax_payment` no longer settles retained/perceived amounts: clear the link (same transaction, rows
     * locked) so they return to the "payable to SUNAT" list and can be voided or settled again.
     *
     * @param array<int, int> $movementIds
     */
    private function releaseWithholdingSettlements(array $movementIds): void
    {
        if (empty($movementIds)) {
            return;
        }

        try {
            if (!DB::connection('tenant')->getSchemaBuilder()->hasTable('treasury_withholdings')) {
                return;
            }
        } catch (\Throwable $e) {
            return;
        }

        $rows = TreasuryWithholding::query()
            ->whereIn('settled_movement_id', $movementIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($rows as $row) {
            $row->settled_movement_id = null;
            $row->save();
        }
    }

    /**
     * Registers the SPOT deposit of a detraction record as a `detraction_deposit` movement (Phase 4).
     *
     * - receivable: my customer deposited into MY Banco de la Nacion detraction account (inflow into that account).
     * - payable: I deposited into my SUPPLIER's account from one of my own accounts (outflow from that account).
     *
     * The detraction amount is always expressed in PEN, so the account must be a PEN account.
     * Must run inside the caller's tenant transaction (DetractionService does so).
     *
     * @param array{side: string, bank_account_id: int, amount_pen: float|string, date: string, establishment_id?: int,
     *     operation_number?: ?string, description?: ?string, source_id: int, counterparty_name?: ?string,
     *     counterparty_document?: ?string} $payload
     */
    public function createDetractionDeposit(array $payload, int $userId): TreasuryMovement
    {
        if ($userId <= 0) {
            throw ValidationException::withMessages([
                'user_id' => ['El usuario que registra el depósito es obligatorio.'],
            ]);
        }

        $side = (string) ($payload['side'] ?? '');
        if (!in_array($side, [DetractionRecord::SIDE_RECEIVABLE, DetractionRecord::SIDE_PAYABLE], true)) {
            throw ValidationException::withMessages([
                'side' => ['El tipo de detracción no es válido.'],
            ]);
        }

        $date = $this->validateDate((string) ($payload['date'] ?? ''));
        $amount = $this->validateAmount($payload['amount_pen'] ?? null, 'amount_pen');
        $accountId = (int) ($payload['bank_account_id'] ?? 0);
        if ($accountId <= 0) {
            throw ValidationException::withMessages([
                'bank_account_id' => ['Seleccione una cuenta bancaria válida.'],
            ]);
        }

        return DB::connection('tenant')->transaction(function () use ($payload, $userId, $side, $date, $amount, $accountId) {
            /** @var BankAccount $account */
            $account = $this->lockAndValidateDestination(BankAccount::class, $accountId, 'bank_account_id', $date);
            $isBn = ($account->account_type ?? null) === 'detraction_bn';

            if ($side === DetractionRecord::SIDE_RECEIVABLE && !$isBn) {
                throw ValidationException::withMessages([
                    'bank_account_id' => ['El depósito recibido debe registrarse en la cuenta de detracciones del Banco de la Nación.'],
                ]);
            }
            if ($side === DetractionRecord::SIDE_PAYABLE && $isBn) {
                throw ValidationException::withMessages([
                    'bank_account_id' => ['El depósito a su proveedor debe salir de una cuenta propia distinta a la cuenta de detracciones.'],
                ]);
            }
            if (strtoupper((string) $account->currency_type_id) !== 'PEN') {
                throw ValidationException::withMessages([
                    'bank_account_id' => ['Las detracciones se depositan en soles. Seleccione una cuenta en PEN.'],
                ]);
            }

            $establishmentId = !empty($account->establishment_id)
                ? (int) $account->establishment_id
                : (int) ($payload['establishment_id'] ?? 0);
            if ($establishmentId <= 0) {
                throw ValidationException::withMessages([
                    'establishment_id' => ['La sede es obligatoria para registrar un movimiento de tesorería.'],
                ]);
            }

            $description = $this->nullableTrim($payload['description'] ?? null)
                ?: ($side === DetractionRecord::SIDE_RECEIVABLE
                    ? 'Depósito de detracción recibido'
                    : 'Depósito de detracción a proveedor');

            return TreasuryMovement::create([
                'establishment_id' => $establishmentId,
                'date' => $date,
                'kind' => 'detraction_deposit',
                'direction' => $side === DetractionRecord::SIDE_RECEIVABLE
                    ? TreasuryMovement::DIRECTION_INFLOW
                    : TreasuryMovement::DIRECTION_OUTFLOW,
                'destination_type' => BankAccount::class,
                'destination_id' => $account->getKey(),
                'currency_type_id' => 'PEN',
                'amount' => $amount,
                'exchange_rate_purchase' => null,
                'exchange_rate_sale' => null,
                'exchange_rate_used' => 1.0,
                'amount_pen' => $amount,
                'operation_number' => $this->operationNumber($payload['operation_number'] ?? null),
                'counterparty_document' => $this->nullableTrim($payload['counterparty_document'] ?? null),
                'counterparty_name' => $this->nullableTrim($payload['counterparty_name'] ?? null),
                'description' => $description,
                'source_type' => DetractionRecord::class,
                'source_id' => (int) ($payload['source_id'] ?? 0) ?: null,
                'state' => TreasuryMovement::STATE_ACTIVE,
                'user_id' => $userId,
            ]);
        });
    }

    /**
     * Registers the release of SPOT funds (Formulario 1697, approved by SUNAT) as two linked
     * `detraction_release` legs: outflow from the BN detraction account, inflow into one of my own accounts.
     * Both accounts are locked in a deterministic order (D10).
     *
     * @return array{out: TreasuryMovement, in: TreasuryMovement, transfer_group: string}
     */
    public function createDetractionRelease(array $payload, int $userId): array
    {
        if ($userId <= 0) {
            throw ValidationException::withMessages([
                'user_id' => ['El usuario que registra la liberación es obligatorio.'],
            ]);
        }

        $date = $this->validateDate((string) ($payload['date'] ?? ''));
        $amount = $this->validateAmount($payload['amount'] ?? null, 'amount');
        $originId = (int) ($payload['origin_id'] ?? 0);
        $targetId = (int) ($payload['target_id'] ?? 0);

        if ($originId <= 0) {
            throw ValidationException::withMessages([
                'origin_id' => ['Seleccione la cuenta de detracciones de origen.'],
            ]);
        }
        if ($targetId <= 0 || $targetId === $originId) {
            throw ValidationException::withMessages([
                'target_id' => ['Seleccione la cuenta de destino de los fondos liberados.'],
            ]);
        }

        return DB::connection('tenant')->transaction(function () use ($payload, $userId, $date, $amount, $originId, $targetId) {
            $locked = [];
            $ids = [$originId, $targetId];
            sort($ids);
            foreach ($ids as $id) {
                $field = $id === $originId ? 'origin_id' : 'target_id';
                $locked[$id] = $this->lockAndValidateDestination(BankAccount::class, $id, $field, $date);
            }

            /** @var BankAccount $origin */
            $origin = $locked[$originId];
            /** @var BankAccount $target */
            $target = $locked[$targetId];

            if (($origin->account_type ?? null) !== 'detraction_bn') {
                throw ValidationException::withMessages([
                    'origin_id' => ['La cuenta de origen debe ser la cuenta de detracciones del Banco de la Nación.'],
                ]);
            }
            if (($target->account_type ?? null) === 'detraction_bn') {
                throw ValidationException::withMessages([
                    'target_id' => ['La cuenta de destino no puede ser una cuenta de detracciones.'],
                ]);
            }
            foreach (['origin_id' => $origin, 'target_id' => $target] as $field => $account) {
                if (strtoupper((string) $account->currency_type_id) !== 'PEN') {
                    throw ValidationException::withMessages([
                        $field => ['La liberación de fondos se realiza en soles. Seleccione cuentas en PEN.'],
                    ]);
                }
            }

            $fallbackEstablishmentId = (int) ($payload['establishment_id'] ?? 0);
            $originEstablishmentId = !empty($origin->establishment_id) ? (int) $origin->establishment_id : $fallbackEstablishmentId;
            $targetEstablishmentId = !empty($target->establishment_id) ? (int) $target->establishment_id : $fallbackEstablishmentId;
            if ($originEstablishmentId <= 0 || $targetEstablishmentId <= 0) {
                throw ValidationException::withMessages([
                    'establishment_id' => ['La sede es obligatoria para registrar un movimiento de tesorería.'],
                ]);
            }

            $group = (string) \Illuminate\Support\Str::uuid();
            $description = $this->nullableTrim($payload['description'] ?? null) ?: 'Liberación de fondos de detracciones (Formulario 1697)';
            $operationNumber = $this->operationNumber($payload['operation_number'] ?? null);

            $base = [
                'date' => $date,
                'kind' => 'detraction_release',
                'destination_type' => BankAccount::class,
                'currency_type_id' => 'PEN',
                'amount' => $amount,
                'exchange_rate_used' => 1.0,
                'amount_pen' => $amount,
                'operation_number' => $operationNumber,
                'description' => $description,
                'transfer_group' => $group,
                'state' => TreasuryMovement::STATE_ACTIVE,
                'user_id' => $userId,
            ];

            $out = TreasuryMovement::create($base + [
                'establishment_id' => $originEstablishmentId,
                'direction' => TreasuryMovement::DIRECTION_OUTFLOW,
                'destination_id' => $origin->getKey(),
            ]);
            $in = TreasuryMovement::create($base + [
                'establishment_id' => $targetEstablishmentId,
                'direction' => TreasuryMovement::DIRECTION_INFLOW,
                'destination_id' => $target->getKey(),
            ]);

            return ['out' => $out, 'in' => $in, 'transfer_group' => $group];
        });
    }

    /**
     * Registers the money effect of a cheque / bill (Phase 7) as a plain `income` / `expense` movement on a bank
     * account, owned by the instrument (source_type = TreasuryInstrument). Only InstrumentService calls this, inside
     * its own transaction; the movement can only be voided through the instrument flow (see void()).
     *
     * @param array{instrument_id: int, direction: int, bank_account_id: int, date: string, amount: float|string,
     *     currency_type_id?: ?string, establishment_id?: int, operation_number?: ?string, sunat_payment_code?: ?string,
     *     counterparty_person_id?: ?int, counterparty_document?: ?string, counterparty_name?: ?string,
     *     description?: ?string, concept_code?: ?string} $payload
     */
    public function createInstrumentMovement(array $payload, int $userId): TreasuryMovement
    {
        if ($userId <= 0) {
            throw ValidationException::withMessages([
                'user_id' => ['El usuario que registra el movimiento es obligatorio.'],
            ]);
        }

        $direction = (int) ($payload['direction'] ?? 0);
        if (!in_array($direction, [TreasuryMovement::DIRECTION_INFLOW, TreasuryMovement::DIRECTION_OUTFLOW], true)) {
            throw ValidationException::withMessages([
                'direction' => ['La dirección del movimiento no es válida.'],
            ]);
        }

        $date = $this->validateDate((string) ($payload['date'] ?? ''));
        $amount = $this->validateAmount($payload['amount'] ?? null, 'amount');
        $accountId = (int) ($payload['bank_account_id'] ?? 0);
        if ($accountId <= 0) {
            throw ValidationException::withMessages([
                'bank_account_id' => ['Seleccione la cuenta bancaria del cobro o pago.'],
            ]);
        }

        /** @var BankAccount $account */
        $account = $this->lockAndValidateDestination(BankAccount::class, $accountId, 'bank_account_id', $date);

        // SPOT funds can only pay SUNAT taxes: never a cheque or bill.
        if (($account->account_type ?? null) === 'detraction_bn') {
            throw ValidationException::withMessages([
                'bank_account_id' => ['La cuenta de detracciones del Banco de la Nación no puede usarse para cheques ni letras.'],
            ]);
        }

        $currency = strtoupper(trim((string) ($payload['currency_type_id'] ?? $account->currency_type_id)));
        if ($currency !== strtoupper((string) $account->currency_type_id)) {
            throw ValidationException::withMessages([
                'bank_account_id' => [
                    "La moneda del documento ({$currency}) debe coincidir con la moneda de la cuenta ({$account->currency_type_id}).",
                ],
            ]);
        }

        $establishmentId = !empty($account->establishment_id)
            ? (int) $account->establishment_id
            : (int) ($payload['establishment_id'] ?? 0);
        if ($establishmentId <= 0) {
            throw ValidationException::withMessages([
                'establishment_id' => ['La sede es obligatoria para registrar un movimiento de tesorería.'],
            ]);
        }

        $fx = $this->resolveExchangeRate($currency, $date, $direction, $payload);
        $amountPen = $currency === 'PEN' ? $amount : Money::round($amount * $fx['used']);

        // The concept is resolved by its stable `code` (never by the editable description) and its absence is an
        // error: silently dropping it would leave the movement without concept in the books.
        $conceptCode = $this->nullableTrim($payload['concept_code'] ?? null);
        $concept = null;
        if ($conceptCode !== null) {
            $concept = TreasuryConcept::query()
                ->where('type', $direction === TreasuryMovement::DIRECTION_INFLOW ? 'income' : 'expense')
                ->where('code', $conceptCode)
                ->where('active', true)
                ->first();
            if (!$concept) {
                throw ValidationException::withMessages([
                    'concept' => ["No se encontró el concepto de tesorería activo «{$conceptCode}». Ejecute las migraciones de Tesorería o reactive el concepto."],
                ]);
            }
        }

        $sunatCode = $this->nullableTrim($payload['sunat_payment_code'] ?? null);
        if ($sunatCode !== null && !array_key_exists($sunatCode, BancarizationChecker::SUNAT_PAYMENT_CODES)) {
            $sunatCode = null;
        }

        $operationNumber = $this->operationNumber($payload['operation_number'] ?? null);
        $description = $this->nullableTrim($payload['description'] ?? null)
            ?: ($direction === TreasuryMovement::DIRECTION_INFLOW ? 'Cobro de cheque o letra' : 'Pago de cheque o letra');

        $movement = TreasuryMovement::create([
            'establishment_id' => $establishmentId,
            'date' => $date,
            'kind' => $direction === TreasuryMovement::DIRECTION_INFLOW ? 'income' : 'expense',
            'direction' => $direction,
            'destination_type' => BankAccount::class,
            'destination_id' => $account->getKey(),
            'currency_type_id' => $currency,
            'amount' => $amount,
            'exchange_rate_purchase' => $fx['purchase'],
            'exchange_rate_sale' => $fx['sale'],
            'exchange_rate_used' => $fx['used'],
            'amount_pen' => $amountPen,
            'sunat_payment_code' => $sunatCode,
            'operation_number' => $operationNumber,
            'counterparty_person_id' => !empty($payload['counterparty_person_id']) ? (int) $payload['counterparty_person_id'] : null,
            'counterparty_document' => $this->nullableTrim($payload['counterparty_document'] ?? null),
            'counterparty_name' => $this->nullableTrim($payload['counterparty_name'] ?? null),
            'treasury_concept_id' => $concept ? $concept->id : null,
            'description' => mb_substr($description, 0, 500),
            'source_type' => TreasuryInstrument::class,
            'source_id' => (int) ($payload['instrument_id'] ?? 0) ?: null,
            'state' => TreasuryMovement::STATE_ACTIVE,
            'user_id' => $userId,
        ]);

        if ($sunatCode !== null && $operationNumber !== null) {
            $this->paymentEvidenceService->upsertForModel($movement, [
                'sunat_payment_code' => $sunatCode,
                'operation_number' => $operationNumber,
                'bank_id' => $account->bank_id,
                'bank_account_id' => $account->id,
                'operation_date' => $date,
                'payer_or_beneficiary_document' => $movement->counterparty_document,
                'payer_or_beneficiary_name' => $movement->counterparty_name,
            ]);
        }

        return $movement;
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

    /**
     * Operation numbers fit a string(60) column: trim and defensively cut (multibyte-safe) instead of failing the write.
     */
    private function operationNumber($value): ?string
    {
        $trimmed = $this->nullableTrim($value);

        return $trimmed === null ? null : mb_substr($trimmed, 0, 60);
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
