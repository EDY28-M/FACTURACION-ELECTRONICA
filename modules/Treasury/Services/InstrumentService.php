<?php

namespace Modules\Treasury\Services;

use App\Models\Tenant\BankAccount;
use App\Models\Tenant\Document;
use App\Models\Tenant\Person;
use App\Models\Tenant\Purchase;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Treasury\Models\TreasuryInstrument;
use Modules\Treasury\Models\TreasuryInstrumentEvent;
use Modules\Treasury\Models\TreasuryMovement;
use Modules\Treasury\Support\DatabaseErrors;
use Modules\Treasury\Support\InstrumentDeadlines;
use Modules\Treasury\Support\Money;

/**
 * Cheques and bills of exchange lifecycle (Phase 7, Ley 27287).
 *
 * - An explicit state machine per instrument type (TRANSITIONS); anything else is refused with a Spanish message.
 * - Money effects (D2/D4):
 *   - UNLINKED instrument: collecting/paying creates an income/expense movement on a bank account through
 *     MovementService; bouncing or reverting it voids that movement.
 *   - LINKED instrument (source = Document / Purchase): collecting/paying registers the payment through the EXISTING
 *     legacy flow (InstrumentPaymentService: DocumentPayment / PurchasePayment + GlobalPayment on the bank account), so
 *     the receivable/payable closes and nothing is counted twice. NO treasury movement is created. Bouncing or
 *     reverting deletes that payment through the same flow.
 * - Lock order: instrument row -> source row -> payment / bank account -> movement rows (instruments are never locked
 *   after an account).
 * - Never calls auth(): callers pass an explicit $userId and $scope (is_admin, user_id, establishment_id).
 */
class InstrumentService
{
    public const TYPE_LABELS = [
        TreasuryInstrument::TYPE_CHEQUE_RECEIVED => 'Cheque recibido',
        TreasuryInstrument::TYPE_CHEQUE_ISSUED => 'Cheque emitido',
        TreasuryInstrument::TYPE_BILL_RECEIVABLE => 'Letra por cobrar',
        TreasuryInstrument::TYPE_BILL_PAYABLE => 'Letra por pagar',
    ];

    /**
     * Allowed transitions: type => from => [to...]. The initial status is delivered for a received cheque (it is
     * already in our hands) and issued for the rest.
     *
     * Bills: issued = girada, delivered = aceptada, deposited = en banco (cobranza), collected = cobrada / pagada.
     */
    public const TRANSITIONS = [
        TreasuryInstrument::TYPE_CHEQUE_RECEIVED => [
            'delivered' => ['deposited', 'voided'],
            'deposited' => ['collected', 'bounced'],
            // collected -> delivered is the audited correction ("Revertir cobro/pago"); bounced stays the real bank
            // rejection. A bounced cheque may be presented again while its legal period is open (deposited), keeping
            // its active_key (the number stays reserved); it is terminal once voided.
            'collected' => ['bounced', 'delivered'],
            'bounced' => ['deposited'],
        ],
        TreasuryInstrument::TYPE_CHEQUE_ISSUED => [
            'issued' => ['delivered', 'voided'],
            'delivered' => ['collected', 'bounced', 'voided'],
            'collected' => ['bounced', 'delivered'],
        ],
        TreasuryInstrument::TYPE_BILL_RECEIVABLE => [
            'issued' => ['delivered', 'voided'],
            'delivered' => ['deposited', 'collected', 'protested', 'voided'],
            'deposited' => ['collected', 'protested'],
            'protested' => ['collected'],
            'collected' => ['delivered'],
        ],
        TreasuryInstrument::TYPE_BILL_PAYABLE => [
            'issued' => ['delivered', 'voided'],
            'delivered' => ['collected', 'protested', 'voided'],
            'protested' => ['collected'],
            'collected' => ['delivered'],
        ],
    ];

    /** Label of the audited correction transition collected -> delivered (any type). */
    public const REVERT_ACTION_LABEL = 'Revertir cobro/pago';

    /** Status label per type (the same status reads differently for a cheque and for a bill). */
    public const STATUS_LABELS = [
        TreasuryInstrument::TYPE_CHEQUE_RECEIVED => [
            'delivered' => 'Recibido',
            'deposited' => 'Depositado',
            'collected' => 'Cobrado',
            'bounced' => 'Rechazado',
            'voided' => 'Anulado',
        ],
        TreasuryInstrument::TYPE_CHEQUE_ISSUED => [
            'issued' => 'Emitido',
            'delivered' => 'Entregado',
            'collected' => 'Cobrado por el beneficiario',
            'bounced' => 'Rechazado',
            'voided' => 'Anulado',
        ],
        TreasuryInstrument::TYPE_BILL_RECEIVABLE => [
            'issued' => 'Girada',
            'delivered' => 'Aceptada',
            'deposited' => 'En banco',
            'collected' => 'Cobrada',
            'protested' => 'Protestada',
            'voided' => 'Anulada',
        ],
        TreasuryInstrument::TYPE_BILL_PAYABLE => [
            'issued' => 'Girada',
            'delivered' => 'Aceptada',
            'collected' => 'Pagada',
            'protested' => 'Protestada',
            'voided' => 'Anulada',
        ],
    ];

    /** Action label of each transition target, per type. */
    public const ACTION_LABELS = [
        TreasuryInstrument::TYPE_CHEQUE_RECEIVED => [
            'deposited' => 'Depositar',
            'collected' => 'Registrar cobro',
            'bounced' => 'Registrar rechazo',
            'voided' => 'Anular',
        ],
        TreasuryInstrument::TYPE_CHEQUE_ISSUED => [
            'delivered' => 'Registrar entrega',
            'collected' => 'Registrar cobro del beneficiario',
            'bounced' => 'Registrar rechazo',
            'voided' => 'Anular',
        ],
        TreasuryInstrument::TYPE_BILL_RECEIVABLE => [
            'delivered' => 'Registrar aceptación',
            'deposited' => 'Enviar a banco',
            'collected' => 'Registrar cobro',
            'protested' => 'Registrar protesto',
            'voided' => 'Anular',
        ],
        TreasuryInstrument::TYPE_BILL_PAYABLE => [
            'delivered' => 'Registrar aceptación',
            'collected' => 'Registrar pago',
            'protested' => 'Registrar protesto',
            'voided' => 'Anular',
        ],
    ];

    /** Statuses in which the instrument still has to be settled (used by the listing and the alerts). */
    public const OPEN_STATUSES = [
        TreasuryInstrument::TYPE_CHEQUE_RECEIVED => ['delivered', 'deposited'],
        TreasuryInstrument::TYPE_CHEQUE_ISSUED => ['issued', 'delivered'],
        TreasuryInstrument::TYPE_BILL_RECEIVABLE => ['issued', 'delivered', 'deposited', 'protested'],
        TreasuryInstrument::TYPE_BILL_PAYABLE => ['issued', 'delivered', 'protested'],
    ];

    /**
     * Statuses projected in the projected cash flow. A protested receivable bill is not projected (collection is
     * uncertain); a protested payable bill is still owed.
     */
    public const PROJECTED_STATUSES = [
        TreasuryInstrument::TYPE_CHEQUE_RECEIVED => ['delivered', 'deposited'],
        TreasuryInstrument::TYPE_CHEQUE_ISSUED => ['issued', 'delivered'],
        TreasuryInstrument::TYPE_BILL_RECEIVABLE => ['issued', 'delivered', 'deposited'],
        TreasuryInstrument::TYPE_BILL_PAYABLE => ['issued', 'delivered', 'protested'],
    ];

    /** Cheques that have not reached the bank yet: the presentation deadline still matters. */
    public const AWAITING_PRESENTATION_STATUSES = [
        TreasuryInstrument::TYPE_CHEQUE_RECEIVED => ['delivered'],
        TreasuryInstrument::TYPE_CHEQUE_ISSUED => ['issued', 'delivered'],
    ];

    /** Bills not yet protested nor settled: the maturity and the protest deadline still matter. */
    public const BILL_DUE_STATUSES = ['issued', 'delivered', 'deposited'];

    private $movementService;
    private $deadlines;
    private $payments;

    public function __construct(MovementService $movementService, InstrumentDeadlines $deadlines, ?InstrumentPaymentService $payments = null)
    {
        $this->movementService = $movementService;
        $this->deadlines = $deadlines;
        $this->payments = $payments ?: new InstrumentPaymentService();
    }

    // ---------------------------------------------------------------------------------------------------------
    // State machine
    // ---------------------------------------------------------------------------------------------------------

    public static function initialStatus(string $type): string
    {
        return $type === TreasuryInstrument::TYPE_CHEQUE_RECEIVED
            ? TreasuryInstrument::STATUS_DELIVERED
            : TreasuryInstrument::STATUS_ISSUED;
    }

    public static function allowedTransitionTargets(string $type, string $status): array
    {
        return self::TRANSITIONS[$type][$status] ?? [];
    }

    public static function statusLabel(string $type, string $status): string
    {
        return self::STATUS_LABELS[$type][$status] ?? $status;
    }

    // ---------------------------------------------------------------------------------------------------------
    // Create / update
    // ---------------------------------------------------------------------------------------------------------

    public function create(array $payload, int $userId, array $scope): TreasuryInstrument
    {
        $this->assertUser($userId);

        $type = (string) ($payload['type'] ?? '');
        if (!in_array($type, TreasuryInstrument::TYPES, true)) {
            throw ValidationException::withMessages(['type' => ['El tipo de documento no es válido.']]);
        }

        try {
            return DB::connection('tenant')->transaction(function () use ($payload, $userId, $scope, $type) {
                $data = $this->normalizePayload($type, $payload, $scope, null);
                $status = self::initialStatus($type);

                $instrument = TreasuryInstrument::create($data + [
                    'type' => $type,
                    'status' => $status,
                    'user_id' => $userId,
                ]);

                $this->recordEvent($instrument, 'created', null, $status, $instrument->issue_date->format('Y-m-d'), $userId, null, null);

                return $instrument->fresh();
            });
        } catch (QueryException $e) {
            if (DatabaseErrors::isDuplicateKey($e)) {
                throw $this->duplicateNumberError();
            }
            throw $e;
        }
    }

    /**
     * Edits the data of an instrument that has not been settled or sent to the bank (issued / delivered, no movement).
     */
    public function update(int $id, array $payload, int $userId, array $scope): TreasuryInstrument
    {
        $this->assertUser($userId);

        try {
            return DB::connection('tenant')->transaction(function () use ($id, $payload, $userId, $scope) {
                $instrument = TreasuryInstrument::whereKey($id)->lockForUpdate()->firstOrFail();
                $this->assertAccess($instrument, $scope);

                if (!$this->isEditable($instrument)) {
                    throw ValidationException::withMessages([
                        'status' => ['Solo se puede editar un documento que aún no fue depositado, cobrado ni anulado.'],
                    ]);
                }

                // The type never changes after creation.
                $data = $this->normalizePayload($instrument->type, $payload, $scope, $instrument);
                $instrument->fill($data);
                $instrument->save();

                $this->recordEvent(
                    $instrument,
                    'updated',
                    $instrument->status,
                    $instrument->status,
                    Carbon::today()->format('Y-m-d'),
                    $userId,
                    'Datos actualizados',
                    null
                );

                return $instrument->fresh();
            });
        } catch (QueryException $e) {
            if (DatabaseErrors::isDuplicateKey($e)) {
                throw $this->duplicateNumberError();
            }
            throw $e;
        }
    }

    public function isEditable(TreasuryInstrument $instrument): bool
    {
        return $instrument->movement_id === null
            && in_array($instrument->status, [TreasuryInstrument::STATUS_ISSUED, TreasuryInstrument::STATUS_DELIVERED], true);
    }

    /**
     * Validates and normalizes the writable columns (shared by create and update).
     *
     * @return array<string, mixed>
     */
    private function normalizePayload(string $type, array $payload, array $scope, ?TreasuryInstrument $current): array
    {
        $isCheque = in_array($type, [TreasuryInstrument::TYPE_CHEQUE_ISSUED, TreasuryInstrument::TYPE_CHEQUE_RECEIVED], true);
        $isInflow = in_array($type, [TreasuryInstrument::TYPE_CHEQUE_RECEIVED, TreasuryInstrument::TYPE_BILL_RECEIVABLE], true);

        $number = trim((string) ($payload['number'] ?? ''));
        if ($number === '') {
            throw ValidationException::withMessages(['number' => ['El número del documento es obligatorio.']]);
        }
        if (mb_strlen($number) > 40) {
            throw ValidationException::withMessages(['number' => ['El número no puede superar los 40 caracteres.']]);
        }

        $amount = $this->validateAmount($payload['amount'] ?? null);
        $issueDate = $this->validateDateString($payload['issue_date'] ?? null, 'issue_date', 'La fecha de emisión');
        if (!config('treasury.allow_future_dates', false) && $issueDate > date('Y-m-d')) {
            throw ValidationException::withMessages([
                'issue_date' => ['La fecha de emisión no puede ser futura. Para un cheque postfechado use la opción de pago diferido.'],
            ]);
        }

        $isDeferred = $isCheque && !empty($payload['is_deferred']);
        if ($isCheque && !$isDeferred) {
            $dueDate = $issueDate;
        } else {
            $dueDate = $this->validateDateString($payload['due_date'] ?? null, 'due_date', $isCheque ? 'La fecha de pago' : 'La fecha de vencimiento');
        }

        if ($isDeferred) {
            if ($dueDate <= $issueDate) {
                throw ValidationException::withMessages([
                    'due_date' => ['La fecha de pago de un cheque de pago diferido debe ser posterior a la fecha de emisión.'],
                ]);
            }
            $max = $this->deadlines->chequeDeferredMaxDays($issueDate);
            $limit = Carbon::parse($issueDate)->addDays($max)->format('Y-m-d');
            if ($dueDate > $limit) {
                throw ValidationException::withMessages([
                    'due_date' => ["El cheque de pago diferido no puede superar {$max} días desde su emisión (hasta {$limit})."],
                ]);
            }
        } elseif (!$isCheque && $dueDate < $issueDate) {
            throw ValidationException::withMessages([
                'due_date' => ['La fecha de vencimiento no puede ser anterior a la fecha de emisión.'],
            ]);
        }

        $currency = strtoupper(trim((string) ($payload['currency_type_id'] ?? 'PEN')));
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            throw ValidationException::withMessages(['currency_type_id' => ['La moneda no es válida.']]);
        }

        // Own bank account (required for an issued cheque: it is the account the cheque is drawn on).
        $bankAccountId = !empty($payload['bank_account_id']) ? (int) $payload['bank_account_id'] : null;
        $account = null;
        if ($bankAccountId !== null) {
            $account = $this->resolveAccount($bankAccountId, $currency, $scope, 'bank_account_id');
        } elseif ($type === TreasuryInstrument::TYPE_CHEQUE_ISSUED) {
            throw ValidationException::withMessages([
                'bank_account_id' => ['Seleccione la cuenta bancaria contra la que se gira el cheque.'],
            ]);
        }

        $bankId = !empty($payload['bank_id']) ? (int) $payload['bank_id'] : null;
        if ($bankId === null && $account !== null && $type === TreasuryInstrument::TYPE_CHEQUE_ISSUED) {
            $bankId = $account->bank_id ? (int) $account->bank_id : null;
        }
        if ($bankId !== null && !DB::connection('tenant')->table('banks')->where('id', $bankId)->exists()) {
            throw ValidationException::withMessages(['bank_id' => ['El banco seleccionado no existe.']]);
        }

        $personId = !empty($payload['person_id']) ? (int) $payload['person_id'] : null;
        $person = null;
        if ($personId !== null) {
            $person = Person::query()->whereKey($personId)->first();
            if (!$person) {
                throw ValidationException::withMessages(['person_id' => ['El cliente o proveedor seleccionado no existe.']]);
            }
        }

        $drawerName = $this->nullableTrim($payload['drawer_name'] ?? null);
        $payeeName = $this->nullableTrim($payload['payee_name'] ?? null);
        if ($person !== null) {
            if ($isInflow && $drawerName === null) {
                $drawerName = $person->name;
            }
            if (!$isInflow && $payeeName === null) {
                $payeeName = $person->name;
            }
        }
        if ($person === null && $drawerName === null && $payeeName === null) {
            throw ValidationException::withMessages([
                'person_id' => [$isInflow
                    ? 'Seleccione el cliente o indique el nombre del girador.'
                    : 'Seleccione el proveedor o indique el nombre del beneficiario.'],
            ]);
        }

        $establishmentId = $this->resolveEstablishment($payload, $scope, $account, $current);

        [$sourceType, $sourceId] = $this->resolveSource($type, $payload, $scope, $currency, $amount, $personId);

        $deadlines = $this->deadlines->compute($type, $issueDate, $dueDate, $isDeferred);

        $key = $this->activeKey($type, $number, $bankId, $bankAccountId, $personId, $drawerName, $payeeName);

        return [
            'number' => $number,
            'establishment_id' => $establishmentId,
            'bank_id' => $bankId,
            'bank_account_id' => $bankAccountId,
            'person_id' => $personId,
            'drawer_name' => $drawerName !== null ? mb_substr($drawerName, 0, 255) : null,
            'payee_name' => $payeeName !== null ? mb_substr($payeeName, 0, 255) : null,
            'issue_date' => $issueDate,
            'due_date' => $dueDate,
            'is_deferred' => $isDeferred,
            'amount' => $amount,
            'currency_type_id' => $currency,
            'presentation_deadline' => $deadlines['presentation_deadline'],
            'protest_deadline' => $deadlines['protest_deadline'],
            'deadline_is_estimate' => $deadlines['deadline_is_estimate'],
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'notes' => $this->nullableTrim($payload['notes'] ?? null) !== null ? mb_substr($this->nullableTrim($payload['notes']), 0, 500) : null,
            'active_key' => $key,
        ];
    }

    private function activeKey(string $type, string $number, ?int $bankId, ?int $bankAccountId, ?int $personId, ?string $drawer, ?string $payee): string
    {
        if ($type === TreasuryInstrument::TYPE_CHEQUE_ISSUED) {
            $scope = 'a' . (int) $bankAccountId;
        } else {
            $party = mb_strtolower((string) ($drawer ?? $payee ?? ''));
            $scope = 'b' . (int) $bankId . 'p' . (int) $personId . 'd' . substr(sha1($party), 0, 8);
        }

        return mb_substr($type . '|' . $scope . '|' . mb_strtolower($number), 0, 120);
    }

    private function duplicateNumberError(): ValidationException
    {
        return ValidationException::withMessages([
            'number' => ['Ya existe un documento vigente con ese número para el mismo banco o cuenta y la misma contraparte.'],
        ]);
    }

    private function resolveEstablishment(array $payload, array $scope, ?BankAccount $account, ?TreasuryInstrument $current): int
    {
        $isAdmin = $this->isAdmin($scope);
        $scopeEst = (int) ($scope['establishment_id'] ?? 0);

        if (!$isAdmin) {
            // A non-admin without an establishment can never create or edit (deny establishment <= 0).
            if ($scopeEst <= 0) {
                throw new AuthorizationException('No tiene una sede asignada para registrar cheques o letras.');
            }

            return $scopeEst;
        }

        if ($current !== null) {
            // Keep the establishment unless the admin explicitly picks another one.
            $requested = !empty($payload['establishment_id']) ? (int) $payload['establishment_id'] : (int) $current->establishment_id;
        } else {
            $requested = !empty($payload['establishment_id']) ? (int) $payload['establishment_id'] : 0;
        }

        if ($account !== null && !empty($account->establishment_id)) {
            if ($requested > 0 && $requested !== (int) $account->establishment_id) {
                throw ValidationException::withMessages([
                    'establishment_id' => ['La sede debe coincidir con la sede asignada a la cuenta bancaria.'],
                ]);
            }
            $requested = (int) $account->establishment_id;
        }

        if ($requested <= 0) {
            $requested = $scopeEst;
        }
        if ($requested <= 0) {
            throw ValidationException::withMessages(['establishment_id' => ['La sede es obligatoria.']]);
        }

        if (!DB::connection('tenant')->table('establishments')->where('id', $requested)->exists()) {
            throw ValidationException::withMessages(['establishment_id' => ['La sede seleccionada no existe.']]);
        }

        return $requested;
    }

    /**
     * Optional link to the document (receivable side) or purchase (payable side) the instrument settles.
     *
     * @return array{0: ?string, 1: ?int}
     */
    private function resolveSource(string $type, array $payload, array $scope, string $currency, float $amount, ?int $personId): array
    {
        $sourceId = !empty($payload['source_id']) ? (int) $payload['source_id'] : null;
        if ($sourceId === null) {
            return [null, null];
        }

        $isInflow = in_array($type, [TreasuryInstrument::TYPE_CHEQUE_RECEIVED, TreasuryInstrument::TYPE_BILL_RECEIVABLE], true);
        $table = $isInflow ? 'documents' : 'purchases';
        $class = $isInflow ? Document::class : Purchase::class;

        $source = DB::connection('tenant')->table($table)->where('id', $sourceId)->first();
        if (!$source || !in_array((string) $source->state_type_id, TreasuryBalanceService::ACCEPTED_STATE_TYPES, true)) {
            throw ValidationException::withMessages([
                'source_id' => [$isInflow ? 'El comprobante seleccionado no existe o no está vigente.' : 'La compra seleccionada no existe o no está vigente.'],
            ]);
        }

        if (!$this->isAdmin($scope)) {
            $userId = (int) ($scope['user_id'] ?? 0);
            $estId = (int) ($scope['establishment_id'] ?? 0);
            if ($userId <= 0 || $estId <= 0 || (int) $source->user_id !== $userId || (int) $source->establishment_id !== $estId) {
                throw new AuthorizationException('No tiene permiso para vincular este comprobante.');
            }
        }

        // Currency, pending balance (amount <= saldo) and customer / supplier must match; re-checked at collection.
        $this->payments->assertCollectable($class, $sourceId, $amount, $currency, $personId);

        return [$class, $sourceId];
    }

    private function resolveAccount(int $accountId, string $currency, array $scope, string $field): BankAccount
    {
        $account = BankAccount::query()->whereKey($accountId)->first();
        if (!$account) {
            throw ValidationException::withMessages([$field => ['La cuenta bancaria seleccionada no existe.']]);
        }

        $this->assertAccountAccess($account, $scope);

        if ((int) $account->status !== 1) {
            throw ValidationException::withMessages([$field => ['La cuenta bancaria seleccionada está inactiva.']]);
        }
        if (($account->account_type ?? null) === 'detraction_bn') {
            throw ValidationException::withMessages([
                $field => ['La cuenta de detracciones del Banco de la Nación no puede usarse para cheques ni letras.'],
            ]);
        }
        if (strtoupper((string) $account->currency_type_id) !== $currency) {
            throw ValidationException::withMessages([
                $field => ["La moneda de la cuenta ({$account->currency_type_id}) debe coincidir con la moneda del documento ({$currency})."],
            ]);
        }

        return $account;
    }

    // ---------------------------------------------------------------------------------------------------------
    // Transitions
    // ---------------------------------------------------------------------------------------------------------

    /**
     * @param array{date?: ?string, bank_account_id?: ?int, notes?: ?string} $payload
     *
     * @return array{instrument: TreasuryInstrument, movement: ?TreasuryMovement, warnings: array<int, string>}
     */
    public function transition(int $id, string $to, array $payload, int $userId, array $scope): array
    {
        $this->assertUser($userId);

        return DB::connection('tenant')->transaction(function () use ($id, $to, $payload, $userId, $scope) {
            // Instrument first (global order: instrument -> account -> movements).
            $instrument = TreasuryInstrument::whereKey($id)->lockForUpdate()->firstOrFail();
            $this->assertAccess($instrument, $scope);

            $from = (string) $instrument->status;
            $allowed = self::allowedTransitionTargets($instrument->type, $from);
            if (!in_array($to, $allowed, true)) {
                $typeLabel = self::TYPE_LABELS[$instrument->type] ?? $instrument->type;
                $fromLabel = self::statusLabel($instrument->type, $from);
                $toLabel = in_array($to, TreasuryInstrument::STATUSES, true) ? self::statusLabel($instrument->type, $to) : $to;
                throw ValidationException::withMessages([
                    'status' => ["{$typeLabel}: no se puede pasar de «{$fromLabel}» a «{$toLabel}»."],
                ]);
            }

            $date = $this->resolveEventDate($payload['date'] ?? null, $instrument);
            $notes = $this->nullableTrim($payload['notes'] ?? null);
            $warnings = [];
            $movement = null;
            $eventMovementId = null;

            switch ($to) {
                case TreasuryInstrument::STATUS_DELIVERED:
                    if ($from === TreasuryInstrument::STATUS_COLLECTED) {
                        // Audited correction: undo a collection / payment registered by mistake.
                        if ($notes === null || mb_strlen($notes) < 3) {
                            throw ValidationException::withMessages([
                                'notes' => ['Indique el motivo de la reversión (mínimo 3 caracteres).'],
                            ]);
                        }
                        $eventMovementId = $this->reverseMoney(
                            $instrument,
                            'Reversión de ' . mb_strtolower(self::TYPE_LABELS[$instrument->type]) . ' ' . $instrument->number . ': ' . $notes,
                            $userId
                        );
                        if ($instrument->type !== TreasuryInstrument::TYPE_CHEQUE_ISSUED) {
                            // The collection account was chosen at collection (a cheque_issued keeps its drawn-on account).
                            $instrument->bank_account_id = null;
                        }
                    }
                    break;

                case TreasuryInstrument::STATUS_DEPOSITED:
                    $accountId = (int) ($payload['bank_account_id'] ?? 0);
                    if ($accountId <= 0) {
                        throw ValidationException::withMessages([
                            'bank_account_id' => [$instrument->type === TreasuryInstrument::TYPE_CHEQUE_RECEIVED
                                ? 'Seleccione la cuenta bancaria donde se deposita el cheque.'
                                : 'Seleccione la cuenta bancaria que gestiona la cobranza de la letra.'],
                        ]);
                    }
                    $account = $this->resolveAccount($accountId, strtoupper((string) $instrument->currency_type_id), $scope, 'bank_account_id');
                    $this->assertAccountEstablishment($account, $instrument);
                    if ($from === TreasuryInstrument::STATUS_BOUNCED) {
                        // Re-presentation of a bounced cheque is only possible inside its legal period.
                        if ($instrument->presentation_deadline !== null && $date > $instrument->presentation_deadline->format('Y-m-d')) {
                            throw ValidationException::withMessages([
                                'date' => ['El plazo de presentación del cheque venció el ' . $instrument->presentation_deadline->format('Y-m-d') . '; ya no puede presentarse nuevamente. Anúlelo y regístrelo otra vez si corresponde.'],
                            ]);
                        }
                    }
                    $instrument->bank_account_id = $account->id;
                    $warnings = array_merge($warnings, $this->presentationWarning($instrument, $date), $this->deferredWarning($instrument, $date));
                    break;

                case TreasuryInstrument::STATUS_COLLECTED:
                    $accountId = $instrument->bank_account_id !== null
                        ? (int) $instrument->bank_account_id
                        : (int) ($payload['bank_account_id'] ?? 0);
                    if ($accountId <= 0) {
                        throw ValidationException::withMessages([
                            'bank_account_id' => [$instrument->isInflow()
                                ? 'Seleccione la cuenta bancaria donde se recibe el cobro.'
                                : 'Seleccione la cuenta bancaria desde la que se realiza el pago.'],
                        ]);
                    }
                    $account = $this->resolveAccount($accountId, strtoupper((string) $instrument->currency_type_id), $scope, 'bank_account_id');
                    $this->assertAccountEstablishment($account, $instrument);

                    if (!empty($instrument->source_type) && !empty($instrument->source_id)) {
                        // Linked to a document / purchase: the money goes through the legacy payment flow (D4), so the
                        // receivable / payable closes. No treasury movement is created for it.
                        $payment = $this->payments->registerPayment($instrument, (int) $account->id, $date, $userId);
                        $instrument->payment_type = get_class($payment);
                        $instrument->payment_id = (int) $payment->getKey();
                    } else {
                        $movement = $this->movementService->createInstrumentMovement([
                            'instrument_id' => (int) $instrument->id,
                            'direction' => $instrument->isInflow() ? TreasuryMovement::DIRECTION_INFLOW : TreasuryMovement::DIRECTION_OUTFLOW,
                            'bank_account_id' => (int) $account->id,
                            'date' => $date,
                            'amount' => $instrument->amount,
                            'currency_type_id' => $instrument->currency_type_id,
                            'establishment_id' => (int) $instrument->establishment_id,
                            'operation_number' => $instrument->number,
                            'sunat_payment_code' => $this->paymentCode($instrument),
                            'counterparty_person_id' => $instrument->person_id,
                            'counterparty_document' => $instrument->person_id ? optional($instrument->person()->first())->number : null,
                            'counterparty_name' => $instrument->isInflow() ? $instrument->drawer_name : $instrument->payee_name,
                            'description' => $this->movementDescription($instrument),
                            'concept_code' => config($instrument->isInflow()
                                ? 'treasury.instruments.concept_income'
                                : 'treasury.instruments.concept_expense'),
                        ], $userId);

                        $instrument->movement_id = $movement->id;
                        $eventMovementId = (int) $movement->id;
                    }

                    $instrument->bank_account_id = $account->id;
                    $warnings = array_merge($warnings, $this->deferredWarning($instrument, $date));

                    if ($from !== TreasuryInstrument::STATUS_PROTESTED) {
                        $warnings = array_merge($warnings, $this->presentationWarning($instrument, $date));
                    }
                    break;

                case TreasuryInstrument::STATUS_BOUNCED:
                    if ($notes === null || mb_strlen($notes) < 3) {
                        throw ValidationException::withMessages([
                            'notes' => ['Indique el motivo del rechazo (mínimo 3 caracteres).'],
                        ]);
                    }
                    // Reverse the money (movement void, or legacy payment deletion for a linked instrument); the
                    // reconciliation guards surface as errors and leave the instrument untouched (transaction rollback).
                    $eventMovementId = $this->reverseMoney(
                        $instrument,
                        'Rechazo de ' . mb_strtolower(self::TYPE_LABELS[$instrument->type]) . ' ' . $instrument->number . ': ' . $notes,
                        $userId
                    );
                    break;

                case TreasuryInstrument::STATUS_PROTESTED:
                    if ($date <= $instrument->due_date->format('Y-m-d')) {
                        throw ValidationException::withMessages([
                            'date' => ['El protesto solo puede registrarse después de la fecha de vencimiento.'],
                        ]);
                    }
                    if ($instrument->protest_deadline !== null && $date > $instrument->protest_deadline->format('Y-m-d')) {
                        $warnings[] = 'El protesto se registró después del plazo legal (' . $instrument->protest_deadline->format('Y-m-d') . ').';
                        $notes = trim(($notes !== null ? $notes . ' ' : '') . '[Fuera de plazo]');
                    }
                    break;

                case TreasuryInstrument::STATUS_VOIDED:
                    if ($notes === null || mb_strlen($notes) < 3) {
                        throw ValidationException::withMessages([
                            'notes' => ['El motivo de anulación es obligatorio (mínimo 3 caracteres).'],
                        ]);
                    }
                    $instrument->voided_at = now();
                    $instrument->voided_by = $userId;
                    $instrument->void_reason = mb_substr($notes, 0, 500);
                    // Free the number so it can be registered again.
                    $instrument->active_key = null;
                    break;
            }

            $instrument->status = $to;
            $instrument->save();

            $this->recordEvent($instrument, 'transition', $from, $to, $date, $userId, $notes, $eventMovementId);

            return [
                'instrument' => $instrument->fresh(),
                'movement' => $movement,
                'warnings' => $warnings,
            ];
        });
    }

    private function resolveEventDate($raw, TreasuryInstrument $instrument): string
    {
        $date = $raw === null || $raw === '' ? Carbon::today()->format('Y-m-d') : $this->validateDateString($raw, 'date', 'La fecha');

        if (!config('treasury.allow_future_dates', false) && $date > date('Y-m-d')) {
            throw ValidationException::withMessages(['date' => ['La fecha de la operación no puede ser futura.']]);
        }
        if ($date < $instrument->issue_date->format('Y-m-d')) {
            throw ValidationException::withMessages(['date' => ['La fecha de la operación no puede ser anterior a la fecha de emisión.']]);
        }

        return $date;
    }

    private function presentationWarning(TreasuryInstrument $instrument, string $date): array
    {
        if ($instrument->presentation_deadline !== null && $date > $instrument->presentation_deadline->format('Y-m-d')) {
            return ['El cheque se presentó después de su plazo de presentación (' . $instrument->presentation_deadline->format('Y-m-d') . '). Verifique que el banco lo acepte.'];
        }

        return [];
    }

    /** A deferred cheque collected before its payment date: allowed, but the user is warned (the bank may refuse it). */
    private function deferredWarning(TreasuryInstrument $instrument, string $date): array
    {
        if ($instrument->type === TreasuryInstrument::TYPE_CHEQUE_RECEIVED || $instrument->type === TreasuryInstrument::TYPE_CHEQUE_ISSUED) {
            if ($instrument->is_deferred && $instrument->due_date !== null && $date < $instrument->due_date->format('Y-m-d')) {
                return ['El cheque es de pago diferido y su fecha de pago es el ' . $instrument->due_date->format('Y-m-d') . '. Verifique que el banco ya lo pueda hacer efectivo.'];
            }
        }

        return [];
    }

    /**
     * Undoes the money effect of a collected / paid instrument: voids its treasury movement (unlinked) or deletes its
     * legacy payment (linked). Both respect the reconciliation guards and run inside the caller's transaction.
     *
     * @return ?int the voided movement id (for the audit trail)
     */
    private function reverseMoney(TreasuryInstrument $instrument, string $reason, int $userId): ?int
    {
        $movementId = null;

        if ($instrument->movement_id !== null) {
            $movementId = (int) $instrument->movement_id;
            $this->movementService->void($movementId, $reason, $userId, false, true);
            $instrument->movement_id = null;
        }

        if ($instrument->payment_id !== null) {
            $this->payments->removePayment($instrument);
            $instrument->payment_type = null;
            $instrument->payment_id = null;
        }

        return $movementId;
    }

    /** An own bank account assigned to an establishment can only be used by instruments of that establishment (also admins). */
    private function assertAccountEstablishment(BankAccount $account, TreasuryInstrument $instrument): void
    {
        if (!empty($account->establishment_id) && (int) $account->establishment_id !== (int) $instrument->establishment_id) {
            throw ValidationException::withMessages([
                'bank_account_id' => ['La cuenta bancaria pertenece a otra sede. Seleccione una cuenta de la sede del cheque o la letra.'],
            ]);
        }
    }

    private function paymentCode(TreasuryInstrument $instrument): ?string
    {
        $code = config('treasury.instruments.sunat_payment_codes.' . ($instrument->isCheque() ? 'cheque' : 'bill'));

        return $code !== null ? (string) $code : null;
    }

    private function movementDescription(TreasuryInstrument $instrument): string
    {
        $label = self::TYPE_LABELS[$instrument->type];
        $party = $instrument->isInflow() ? $instrument->drawer_name : $instrument->payee_name;
        $verb = $instrument->isInflow() ? 'Cobro' : 'Pago';

        return trim("{$verb} de " . mb_strtolower($label) . " {$instrument->number}" . ($party ? " - {$party}" : ''));
    }

    private function recordEvent(
        TreasuryInstrument $instrument,
        string $action,
        ?string $from,
        string $to,
        string $date,
        int $userId,
        ?string $notes,
        ?int $movementId
    ): void {
        TreasuryInstrumentEvent::create([
            'instrument_id' => $instrument->id,
            'action' => $action,
            'from_status' => $from,
            'to_status' => $to,
            'date' => $date,
            'user_id' => $userId,
            'notes' => $notes !== null ? mb_substr($notes, 0, 500) : null,
            'movement_id' => $movementId,
        ]);
    }

    // ---------------------------------------------------------------------------------------------------------
    // Access
    // ---------------------------------------------------------------------------------------------------------

    /**
     * Non-admins only manage the instruments they registered, in their own establishment. A non-admin without an
     * establishment never matches (deny establishment <= 0).
     */
    public function assertAccess(TreasuryInstrument $instrument, array $scope): void
    {
        if ($this->isAdmin($scope)) {
            return;
        }

        $userId = (int) ($scope['user_id'] ?? 0);
        $estId = (int) ($scope['establishment_id'] ?? 0);

        if ($userId <= 0 || $estId <= 0
            || (int) $instrument->user_id !== $userId
            || (int) $instrument->establishment_id !== $estId) {
            throw new AuthorizationException('No tiene permiso para gestionar este cheque o letra.');
        }
    }

    public function assertAccountAccess(BankAccount $account, array $scope): void
    {
        if ($this->isAdmin($scope)) {
            return;
        }

        $estId = (int) ($scope['establishment_id'] ?? 0);
        if ($estId <= 0 || (!empty($account->establishment_id) && (int) $account->establishment_id !== $estId)) {
            throw new AuthorizationException('No tiene permiso para operar sobre una cuenta bancaria de otra sede.');
        }
    }

    private function isAdmin(array $scope): bool
    {
        return array_key_exists('is_admin', $scope) ? (bool) $scope['is_admin'] : true;
    }

    private function assertUser(int $userId): void
    {
        if ($userId <= 0) {
            throw ValidationException::withMessages(['user_id' => ['El usuario que registra la operación es obligatorio.']]);
        }
    }

    // ---------------------------------------------------------------------------------------------------------
    // Read side
    // ---------------------------------------------------------------------------------------------------------

    /**
     * Applies the non-admin visibility rules to a query on treasury_instruments.
     *
     * @param \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder $query
     */
    public function applyScope($query, array $scope, ?int $requestedEstablishmentId = null)
    {
        if ($this->isAdmin($scope)) {
            if ($requestedEstablishmentId) {
                $query->where('establishment_id', $requestedEstablishmentId);
            }

            return $query;
        }

        $userId = (int) ($scope['user_id'] ?? 0);
        $estId = (int) ($scope['establishment_id'] ?? 0);
        if ($userId <= 0 || $estId <= 0) {
            // Deny: match nothing.
            return $query->whereRaw('1 = 0');
        }

        return $query->where('user_id', $userId)->where('establishment_id', $estId);
    }

    /**
     * @param array{status?: ?string, establishment_id?: ?int, date_start?: ?string, date_end?: ?string,
     *     search?: ?string, page?: int, per_page?: int, is_admin?: bool, user_id?: ?int} $filters
     */
    public function listRecords(string $type, array $filters): array
    {
        if (!in_array($type, TreasuryInstrument::TYPES, true)) {
            throw ValidationException::withMessages(['type' => ['El tipo de documento no es válido.']]);
        }

        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min((int) config('treasury.instruments.max_per_page', 100), max(1, (int) ($filters['per_page'] ?? 20)));
        $asOf = Carbon::today()->format('Y-m-d');

        $this->refreshOpenBillDeadlines();

        $query = TreasuryInstrument::query()->where('type', $type);
        $this->applyScope($query, $filters, !empty($filters['establishment_id']) ? (int) $filters['establishment_id'] : null);

        $status = (string) ($filters['status'] ?? 'all');
        if ($status === 'open') {
            $query->whereIn('status', self::OPEN_STATUSES[$type]);
        } elseif ($status !== 'all' && in_array($status, TreasuryInstrument::STATUSES, true)) {
            $query->where('status', $status);
        }

        if (!empty($filters['date_start'])) {
            $query->whereDate('due_date', '>=', $filters['date_start']);
        }
        if (!empty($filters['date_end'])) {
            $query->whereDate('due_date', '<=', $filters['date_end']);
        }

        $search = $this->nullableTrim($filters['search'] ?? null);
        if ($search !== null) {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%';
            $query->where(function ($q) use ($like) {
                $q->where('number', 'like', $like)
                    ->orWhere('drawer_name', 'like', $like)
                    ->orWhere('payee_name', 'like', $like);
            });
        }

        // Totals of the open amounts (by currency) over the whole filtered set, not only the page.
        $totalsQuery = clone $query;
        $totals = $totalsQuery->whereIn('status', self::OPEN_STATUSES[$type])
            ->selectRaw('currency_type_id, count(*) as cnt, sum(amount) as total')
            ->groupBy('currency_type_id')
            ->get()
            ->map(function ($row) {
                return [
                    'currency_type_id' => $row->currency_type_id,
                    'count' => (int) $row->cnt,
                    'amount' => Money::round((float) $row->total),
                ];
            })->values()->all();

        $total = (clone $query)->count();
        $rows = $query->with(['bank', 'bankAccount', 'person'])
            ->orderBy('due_date')
            ->orderBy('id')
            ->forPage($page, $perPage)
            ->get()
            ->map(function (TreasuryInstrument $row) use ($asOf) {
                return $this->present($row, $asOf);
            })->values()->all();

        return [
            'rows' => $rows,
            'totals' => $totals,
            'meta' => [
                'total' => $total,
                'per_page' => $perPage,
                'current_page' => $page,
                'last_page' => (int) max(1, ceil($total / $perPage)),
            ],
        ];
    }

    public function history(int $id, array $scope): array
    {
        $instrument = TreasuryInstrument::whereKey($id)->firstOrFail();
        $this->assertAccess($instrument, $scope);
        $this->refreshOpenBillDeadlines($id);
        $instrument = $instrument->fresh();

        $events = TreasuryInstrumentEvent::query()
            ->where('instrument_id', $id)
            ->with('user')
            ->orderBy('id')
            ->get();

        return [
            'instrument' => $this->present($instrument->load(['bank', 'bankAccount', 'person'])),
            'events' => $events->map(function (TreasuryInstrumentEvent $event) use ($instrument) {
                return [
                    'id' => (int) $event->id,
                    'action' => $event->action,
                    'from_status' => $event->from_status,
                    'to_status' => $event->to_status,
                    'from_label' => $event->from_status ? self::statusLabel($instrument->type, $event->from_status) : null,
                    'to_label' => self::statusLabel($instrument->type, $event->to_status),
                    'date' => $event->date ? $event->date->format('Y-m-d') : null,
                    'user' => $event->user ? $event->user->name : null,
                    'notes' => $event->notes,
                    'movement_id' => $event->movement_id ? (int) $event->movement_id : null,
                ];
            })->values()->all(),
        ];
    }

    public function present(TreasuryInstrument $row, ?string $asOf = null): array
    {
        $asOf = $asOf ?: Carbon::today()->format('Y-m-d');
        $type = $row->type;
        $status = (string) $row->status;

        $actions = [];
        foreach (self::allowedTransitionTargets($type, $status) as $target) {
            $isRevert = $status === TreasuryInstrument::STATUS_COLLECTED && $target === TreasuryInstrument::STATUS_DELIVERED;
            $destructive = $isRevert || in_array($target, [TreasuryInstrument::STATUS_BOUNCED, TreasuryInstrument::STATUS_VOIDED], true);
            $actions[] = [
                'to' => $target,
                'label' => $isRevert
                    ? self::REVERT_ACTION_LABEL
                    : ($status === TreasuryInstrument::STATUS_BOUNCED && $target === TreasuryInstrument::STATUS_DEPOSITED
                        ? 'Volver a depositar'
                        : (self::ACTION_LABELS[$type][$target] ?? $target)),
                'needs_account' => $this->transitionNeedsAccount($row, $target),
                'needs_notes' => $destructive,
                'destructive' => $destructive,
                'warn_if_early' => $row->is_deferred && $target === TreasuryInstrument::STATUS_COLLECTED,
            ];
        }

        return [
            'id' => (int) $row->id,
            'type' => $type,
            'type_label' => self::TYPE_LABELS[$type] ?? $type,
            'number' => $row->number,
            'establishment_id' => (int) $row->establishment_id,
            'bank_id' => $row->bank_id ? (int) $row->bank_id : null,
            'bank_name' => $row->bank ? $row->bank->description : null,
            'bank_account_id' => $row->bank_account_id ? (int) $row->bank_account_id : null,
            'bank_account_label' => $row->bankAccount ? trim($row->bankAccount->description . ' (' . $row->bankAccount->number . ')') : null,
            'person_id' => $row->person_id ? (int) $row->person_id : null,
            'person_name' => $row->person ? $row->person->name : null,
            'drawer_name' => $row->drawer_name,
            'payee_name' => $row->payee_name,
            'counterparty_name' => $row->isInflow() ? $row->drawer_name : $row->payee_name,
            'issue_date' => $row->issue_date ? $row->issue_date->format('Y-m-d') : null,
            'due_date' => $row->due_date ? $row->due_date->format('Y-m-d') : null,
            'is_deferred' => (bool) $row->is_deferred,
            'amount' => (float) $row->amount,
            'currency_type_id' => $row->currency_type_id,
            'status' => $status,
            'status_label' => self::statusLabel($type, $status),
            'presentation_deadline' => $row->presentation_deadline ? $row->presentation_deadline->format('Y-m-d') : null,
            'protest_deadline' => $row->protest_deadline ? $row->protest_deadline->format('Y-m-d') : null,
            'deadline_is_estimate' => (bool) $row->deadline_is_estimate,
            'flags' => $this->flags($row, $asOf),
            'movement_id' => $row->movement_id ? (int) $row->movement_id : null,
            'payment_id' => $row->payment_id ? (int) $row->payment_id : null,
            'payment_type' => $row->payment_type ? ($row->payment_type === \App\Models\Tenant\DocumentPayment::class ? 'document_payment' : 'purchase_payment') : null,
            'source_type' => $row->source_type ? ($row->source_type === Document::class ? 'document' : 'purchase') : null,
            'source_id' => $row->source_id ? (int) $row->source_id : null,
            'notes' => $row->notes,
            'void_reason' => $row->void_reason,
            'is_editable' => $this->isEditable($row),
            'actions' => $actions,
        ];
    }

    /**
     * Recomputes the protest deadline (and its estimate flag) of the bills that are still open. The deadline depends
     * on the holidays table, which can change after the bill was saved (a holiday added, removed or a new year loaded),
     * so the value stored at save time can go stale. Only rows whose value really changed are written, without
     * touching timestamps or the audit trail (it is derived data). Cheques do not depend on holidays.
     *
     * @return int number of rows updated
     */
    public function refreshOpenBillDeadlines(?int $onlyId = null): int
    {
        $query = TreasuryInstrument::query()
            ->whereIn('type', [TreasuryInstrument::TYPE_BILL_RECEIVABLE, TreasuryInstrument::TYPE_BILL_PAYABLE])
            ->whereIn('status', self::BILL_DUE_STATUSES);
        if ($onlyId !== null) {
            $query->whereKey($onlyId);
        }

        $updated = 0;
        foreach ($query->get() as $row) {
            $fresh = $this->deadlines->compute(
                $row->type,
                $row->issue_date->format('Y-m-d'),
                $row->due_date->format('Y-m-d'),
                (bool) $row->is_deferred
            );
            $current = $row->protest_deadline ? $row->protest_deadline->format('Y-m-d') : null;

            if ($current === $fresh['protest_deadline'] && (bool) $row->deadline_is_estimate === (bool) $fresh['deadline_is_estimate']) {
                continue;
            }

            DB::connection('tenant')->table('treasury_instruments')->where('id', $row->id)->update([
                'protest_deadline' => $fresh['protest_deadline'],
                'deadline_is_estimate' => $fresh['deadline_is_estimate'] ? 1 : 0,
            ]);
            $updated++;
        }

        return $updated;
    }

    /**
     * @return array<int, string> subset of: presentation_soon, presentation_expired, due_soon, overdue, protest_open,
     *                            protest_soon, protest_expired
     */
    private function flags(TreasuryInstrument $row, string $asOf): array
    {
        $flags = [];
        $status = (string) $row->status;
        $soonCheque = Carbon::parse($asOf)->addDays((int) config('treasury.instruments.alert_cheque_days', 7))->format('Y-m-d');
        $soonBill = Carbon::parse($asOf)->addDays((int) config('treasury.instruments.alert_bill_days', 7))->format('Y-m-d');
        $soonProtest = Carbon::parse($asOf)->addDays((int) config('treasury.instruments.alert_protest_days', 3))->format('Y-m-d');

        if ($row->isCheque() && in_array($status, self::AWAITING_PRESENTATION_STATUSES[$row->type], true) && $row->presentation_deadline) {
            $deadline = $row->presentation_deadline->format('Y-m-d');
            if ($deadline < $asOf) {
                $flags[] = 'presentation_expired';
            } elseif ($deadline <= $soonCheque) {
                $flags[] = 'presentation_soon';
            }
        }

        if ($row->isBill() && in_array($status, self::BILL_DUE_STATUSES, true)) {
            $due = $row->due_date->format('Y-m-d');
            if ($due < $asOf) {
                $flags[] = 'overdue';
                if ($row->protest_deadline) {
                    $deadline = $row->protest_deadline->format('Y-m-d');
                    if ($deadline < $asOf) {
                        $flags[] = 'protest_expired';
                    } elseif ($deadline <= $soonProtest) {
                        $flags[] = 'protest_soon';
                    } else {
                        $flags[] = 'protest_open';
                    }
                }
            } elseif ($due <= $soonBill) {
                $flags[] = 'due_soon';
            }
        }

        return $flags;
    }

    private function transitionNeedsAccount(TreasuryInstrument $row, string $target): bool
    {
        if ($target === TreasuryInstrument::STATUS_DEPOSITED) {
            return true;
        }

        return $target === TreasuryInstrument::STATUS_COLLECTED && $row->bank_account_id === null;
    }

    // ---------------------------------------------------------------------------------------------------------
    // Lookups for the screen
    // ---------------------------------------------------------------------------------------------------------

    public function lookupPersons(string $type, ?string $search, int $limit = 20): array
    {
        $personType = in_array($type, [TreasuryInstrument::TYPE_CHEQUE_RECEIVED, TreasuryInstrument::TYPE_BILL_RECEIVABLE], true)
            ? 'customers'
            : 'suppliers';

        $query = Person::query()->where('type', $personType);
        $search = $this->nullableTrim($search);
        if ($search !== null) {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%';
            $query->where(function ($q) use ($like) {
                $q->where('name', 'like', $like)->orWhere('number', 'like', $like);
            });
        }

        return $query->orderBy('name')->limit($limit)->get(['id', 'name', 'number'])->map(function ($person) {
            return ['id' => (int) $person->id, 'name' => $person->name, 'number' => $person->number];
        })->values()->all();
    }

    /**
     * Documents (receivable side) or purchases (payable side) that an instrument can be linked to.
     */
    public function lookupSources(string $type, ?string $search, array $scope, int $limit = 20): array
    {
        $isInflow = in_array($type, [TreasuryInstrument::TYPE_CHEQUE_RECEIVED, TreasuryInstrument::TYPE_BILL_RECEIVABLE], true);
        $table = $isInflow ? 'documents' : 'purchases';

        $query = DB::connection('tenant')->table($table)
            ->whereIn('state_type_id', TreasuryBalanceService::ACCEPTED_STATE_TYPES)
            ->where('total_canceled', 0);

        if (!$this->isAdmin($scope)) {
            $userId = (int) ($scope['user_id'] ?? 0);
            $estId = (int) ($scope['establishment_id'] ?? 0);
            if ($userId <= 0 || $estId <= 0) {
                return [];
            }
            $query->where('user_id', $userId)->where('establishment_id', $estId);
        }

        $search = $this->nullableTrim($search);
        if ($search !== null) {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%';
            $query->where(function ($q) use ($like) {
                $q->where('series', 'like', $like)->orWhere('number', 'like', $like);
            });
        }

        return $query->orderByDesc('id')->limit($limit)->get(['id', 'series', 'number', 'total', 'currency_type_id', 'date_of_issue'])
            ->map(function ($row) {
                return [
                    'id' => (int) $row->id,
                    'label' => trim($row->series . '-' . $row->number),
                    'total' => (float) $row->total,
                    'currency_type_id' => $row->currency_type_id,
                    'date_of_issue' => substr((string) $row->date_of_issue, 0, 10),
                ];
            })->values()->all();
    }

    // ---------------------------------------------------------------------------------------------------------
    // Alerts (Treasury dashboard)
    // ---------------------------------------------------------------------------------------------------------

    /**
     * @param array{is_admin?: bool, user_id?: ?int, establishment_id?: ?int} $scope
     *
     * @return array{counts: array<string, int>, total: int, items: array<int, array<string, mixed>>}
     */
    public function getAlerts(array $scope, ?string $asOfDate = null): array
    {
        $asOf = $asOfDate ?: Carbon::today()->format('Y-m-d');
        $this->refreshOpenBillDeadlines();
        $maxItems = max(1, (int) config('treasury.instruments.alert_max_items', 10));
        $soonCheque = Carbon::parse($asOf)->addDays((int) config('treasury.instruments.alert_cheque_days', 7))->format('Y-m-d');
        $soonBill = Carbon::parse($asOf)->addDays((int) config('treasury.instruments.alert_bill_days', 7))->format('Y-m-d');
        $soonProtest = Carbon::parse($asOf)->addDays((int) config('treasury.instruments.alert_protest_days', 3))->format('Y-m-d');

        $base = function () use ($scope) {
            $q = TreasuryInstrument::query();
            $this->applyScope($q, $scope, !empty($scope['establishment_id']) && $this->isAdmin($scope) ? (int) $scope['establishment_id'] : null);

            return $q;
        };

        $cheques = function () use ($base) {
            return $base()->where(function ($q) {
                foreach (self::AWAITING_PRESENTATION_STATUSES as $chequeType => $statuses) {
                    $q->orWhere(function ($w) use ($chequeType, $statuses) {
                        $w->where('type', $chequeType)->whereIn('status', $statuses);
                    });
                }
            })->whereNotNull('presentation_deadline');
        };

        $bills = function () use ($base) {
            return $base()->whereIn('type', [TreasuryInstrument::TYPE_BILL_RECEIVABLE, TreasuryInstrument::TYPE_BILL_PAYABLE])
                ->whereIn('status', self::BILL_DUE_STATUSES);
        };

        $groups = [
            'cheque_presentation_soon' => $cheques()->whereDate('presentation_deadline', '>=', $asOf)->whereDate('presentation_deadline', '<=', $soonCheque)->orderBy('presentation_deadline'),
            'cheque_presentation_expired' => $cheques()->whereDate('presentation_deadline', '<', $asOf)->orderBy('presentation_deadline'),
            'bill_due_soon' => $bills()->whereDate('due_date', '>=', $asOf)->whereDate('due_date', '<=', $soonBill)->orderBy('due_date'),
            'bill_overdue' => $bills()->whereDate('due_date', '<', $asOf)->orderBy('due_date'),
            'protest_deadline_soon' => $bills()->where('type', TreasuryInstrument::TYPE_BILL_RECEIVABLE)
                ->whereDate('due_date', '<', $asOf)->whereDate('protest_deadline', '>=', $asOf)->whereDate('protest_deadline', '<=', $soonProtest)->orderBy('protest_deadline'),
            'protest_deadline_expired' => $bills()->where('type', TreasuryInstrument::TYPE_BILL_RECEIVABLE)
                ->whereDate('due_date', '<', $asOf)->whereDate('protest_deadline', '<', $asOf)->orderBy('protest_deadline'),
        ];

        $counts = [];
        foreach ($groups as $kind => $query) {
            $counts[$kind] = (int) (clone $query)->count();
        }

        // Items: every group contributes up to $maxItems rows, and an instrument appears once (a bill that is overdue
        // and past its protest window is listed under the most specific group). Specific groups are filled first.
        $order = [
            'protest_deadline_expired',
            'protest_deadline_soon',
            'cheque_presentation_expired',
            'cheque_presentation_soon',
            'bill_overdue',
            'bill_due_soon',
        ];
        $items = [];
        $seen = [];
        foreach ($order as $kind) {
            $taken = 0;
            // Fetch enough rows to still get $maxItems new ones after skipping the ones already listed.
            foreach ((clone $groups[$kind])->limit($maxItems + count($seen))->get() as $row) {
                if ($taken >= $maxItems) {
                    break;
                }
                if (isset($seen[(int) $row->id])) {
                    continue;
                }
                $seen[(int) $row->id] = true;
                $taken++;
                $items[] = [
                    'kind' => $kind,
                    'id' => (int) $row->id,
                    'type' => $row->type,
                    'type_label' => self::TYPE_LABELS[$row->type],
                    'number' => $row->number,
                    'counterparty_name' => $row->isInflow() ? $row->drawer_name : $row->payee_name,
                    'amount' => (float) $row->amount,
                    'currency_type_id' => $row->currency_type_id,
                    'due_date' => $row->due_date->format('Y-m-d'),
                    'presentation_deadline' => $row->presentation_deadline ? $row->presentation_deadline->format('Y-m-d') : null,
                    'protest_deadline' => $row->protest_deadline ? $row->protest_deadline->format('Y-m-d') : null,
                ];
            }
        }

        return [
            'counts' => $counts,
            // protest_deadline_* are subsets of bill_overdue, so they are not added again.
            'total' => $counts['cheque_presentation_soon'] + $counts['cheque_presentation_expired']
                + $counts['bill_due_soon'] + $counts['bill_overdue'],
            'items' => $items,
        ];
    }

    // ---------------------------------------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------------------------------------

    private function validateAmount($raw): float
    {
        if (!is_numeric($raw)) {
            throw ValidationException::withMessages(['amount' => ['El monto debe ser un valor numérico válido.']]);
        }
        $rounded = Money::round((float) $raw);
        if ($rounded < Money::MIN_AMOUNT || $rounded > Money::MAX_AMOUNT) {
            throw ValidationException::withMessages(['amount' => ['El monto debe estar entre 0.01 y 9,999,999,999.99.']]);
        }

        return $rounded;
    }

    private function validateDateString($raw, string $field, string $label): string
    {
        $value = trim((string) $raw);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) || !checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))) {
            throw ValidationException::withMessages([$field => ["{$label} debe tener el formato AAAA-MM-DD."]]);
        }

        return $value;
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
