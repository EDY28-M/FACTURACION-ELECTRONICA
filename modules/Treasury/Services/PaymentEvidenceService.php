<?php

namespace Modules\Treasury\Services;

use App\Models\Tenant\BankAccount;
use App\Models\Tenant\Cash;
use App\Models\Tenant\DocumentPayment;
use App\Models\Tenant\PurchasePayment;
use App\Models\Tenant\SaleNotePayment;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Expense\Models\ExpensePayment;
use Modules\Finance\Models\IncomePayment;
use Modules\Pos\Models\CashTransaction;
use Modules\Sale\Models\ContractPayment;
use Modules\Sale\Models\QuotationPayment;
use Modules\Sale\Models\TechnicalServicePayment;
use Modules\Treasury\Models\PaymentEvidence;
use Modules\Treasury\Models\TreasuryMovement;
use Modules\Treasury\Support\Money;

/**
 * Manages polymorphic bancarization evidence (Decision D5) across all 9 legacy payment models
 * and TreasuryMovement using a strict whitelist of payable aliases.
 */
class PaymentEvidenceService
{
    /**
     * Strict whitelist mapping URL aliases to tenant Eloquent model classes.
     */
    public const PAYABLE_TYPES = [
        'document_payment' => DocumentPayment::class,
        'sale_note_payment' => SaleNotePayment::class,
        'purchase_payment' => PurchasePayment::class,
        'expense_payment' => ExpensePayment::class,
        'quotation_payment' => QuotationPayment::class,
        'contract_payment' => ContractPayment::class,
        'income_payment' => IncomePayment::class,
        'cash_transaction' => CashTransaction::class,
        'technical_service_payment' => TechnicalServicePayment::class,
        'treasury_movement' => TreasuryMovement::class,
    ];

    /**
     * Required module values per payable alias (mirrored from config/permissions.php).
     */
    public const REQUIRED_MODULES_BY_ALIAS = [
        'treasury_movement' => ['treasury'],
        'document_payment' => ['treasury', 'documents', 'pos', 'finance'],
        'sale_note_payment' => ['treasury', 'documents', 'pos', 'finance'],
        'quotation_payment' => ['treasury', 'documents', 'finance'],
        'contract_payment' => ['treasury', 'documents', 'finance'],
        'technical_service_payment' => ['treasury', 'documents', 'finance'],
        'purchase_payment' => ['treasury', 'purchases', 'finance'],
        'expense_payment' => ['treasury', 'purchases', 'finance'],
        'income_payment' => ['treasury', 'finance'],
        'cash_transaction' => ['treasury', 'pos', 'finance'],
    ];

    private $bancarizationChecker;

    public function __construct(BancarizationChecker $bancarizationChecker)
    {
        $this->bancarizationChecker = $bancarizationChecker;
    }

    public function resolveModelClass(string $alias): string
    {
        $normalized = strtolower(trim($alias));
        if (!isset(self::PAYABLE_TYPES[$normalized])) {
            throw ValidationException::withMessages([
                'payable_type' => ['Tipo de pago no soportado para evidencia de bancarización.'],
            ]);
        }

        return self::PAYABLE_TYPES[$normalized];
    }

    public function requiredModulesForAlias(string $alias): array
    {
        $normalized = strtolower(trim($alias));
        if (!isset(self::REQUIRED_MODULES_BY_ALIAS[$normalized])) {
            throw ValidationException::withMessages([
                'payable_type' => ['Tipo de pago no soportado para evidencia de bancarización.'],
            ]);
        }

        return self::REQUIRED_MODULES_BY_ALIAS[$normalized];
    }

    public function resolvePayable(string $alias, int $id): Model
    {
        $class = $this->resolveModelClass($alias);

        return $class::findOrFail($id);
    }

    /**
     * Enforces record-level ownership/establishment authorization for non-admin users.
     */
    public function canUserAccessPayable(Model $payable, $user): bool
    {
        if (!$user) {
            return false;
        }

        if (($user->type ?? null) === 'admin') {
            return true;
        }

        $userId = (int) ($user->id ?? 0);
        $userEstablishmentId = (int) ($user->establishment_id ?? 0);

        if ($payable instanceof TreasuryMovement) {
            if ((int) $payable->establishment_id !== $userEstablishmentId || (int) $payable->user_id !== $userId) {
                return false;
            }
            if ($payable->destination_type === Cash::class) {
                $cash = Cash::find($payable->destination_id);

                return $cash !== null && (int) $cash->user_id === $userId;
            }

            return true;
        }

        if ($payable instanceof CashTransaction) {
            $cash = $payable->cash ?: Cash::find($payable->cash_id);

            return $cash !== null && (int) $cash->user_id === $userId;
        }

        if (method_exists($payable, 'associated_record_payment')) {
            $parent = $payable->associated_record_payment;

            return $parent !== null && (int) ($parent->user_id ?? 0) === $userId;
        }

        return false;
    }

    public function getWithDefaults(string $alias, int $id): array
    {
        $normalizedAlias = strtolower(trim($alias));
        $payable = $this->resolvePayable($normalizedAlias, $id);
        $payableClass = get_class($payable);

        $evidence = PaymentEvidence::where('payable_type', $payableClass)
            ->where('payable_id', $payable->getKey())
            ->first();

        $extracted = $this->extractPayableContext($payable);
        $effectiveSunatCode = $evidence
            ? $evidence->sunat_payment_code
            : ($extracted['defaults']['sunat_payment_code'] ?? null);

        $bancarization = $this->bancarizationChecker->evaluate(
            $extracted['amount'],
            $extracted['currency_type_id'],
            $extracted['defaults']['operation_date'],
            $extracted['is_cash'],
            $effectiveSunatCode,
            false,
            $extracted['exchange_rate_sale']
        );

        if (empty($extracted['defaults']['sunat_payment_code']) && !empty($bancarization['suggested_sunat_code'])) {
            $extracted['defaults']['sunat_payment_code'] = $bancarization['suggested_sunat_code'];
        }

        return [
            'alias' => $normalizedAlias,
            'payable_id' => (int) $payable->getKey(),
            'evidence' => $evidence ? $this->serializeEvidence($evidence) : null,
            'defaults' => $extracted['defaults'],
            'context' => [
                'amount' => Money::round($extracted['amount']),
                'currency_type_id' => $extracted['currency_type_id'],
                'bancarization' => $bancarization,
            ],
        ];
    }

    public function upsert(string $alias, int $id, array $data): PaymentEvidence
    {
        $payable = $this->resolvePayable($alias, $id);

        return DB::connection('tenant')->transaction(function () use ($payable, $data) {
            return $this->upsertForModel($payable, $data);
        });
    }

    public function upsertForModel(Model $payable, array $data): PaymentEvidence
    {
        $sunatCode = isset($data['sunat_payment_code']) ? trim((string) $data['sunat_payment_code']) : '';
        if ($sunatCode === '' || !array_key_exists($sunatCode, BancarizationChecker::SUNAT_PAYMENT_CODES)) {
            throw ValidationException::withMessages([
                'sunat_payment_code' => ['Seleccione un código de medio de pago SUNAT válido (Tabla 1).'],
            ]);
        }

        $operationDate = isset($data['operation_date']) ? $this->normalizeDate($data['operation_date']) : null;
        if (!$operationDate) {
            throw ValidationException::withMessages([
                'operation_date' => ['La fecha de operación es obligatoria.'],
            ]);
        }

        $exception = isset($data['bancarization_exception']) && $data['bancarization_exception'] !== ''
            ? trim((string) $data['bancarization_exception'])
            : null;

        if ($exception !== null && !in_array($exception, BancarizationChecker::ALLOWED_EXCEPTIONS, true)) {
            throw ValidationException::withMessages([
                'bancarization_exception' => ['La excepción de bancarización seleccionada no es válida.'],
            ]);
        }

        $extracted = $this->extractPayableContext($payable);
        $isCash = in_array($sunatCode, ['008', '009'], true);
        $bancarization = $this->bancarizationChecker->evaluate(
            $extracted['amount'],
            $extracted['currency_type_id'],
            $operationDate,
            $isCash,
            $sunatCode,
            !empty($data['low_compliance_counterparty']),
            $extracted['exchange_rate_sale']
        );

        if ($bancarization['requires_exception_or_bank_method'] && $exception === null) {
            throw ValidationException::withMessages([
                'bancarization_exception' => [
                    'El pago supera el umbral de bancarización y usa efectivo; indique una excepción del art. 6 o cambie el medio de pago.',
                ],
            ]);
        }

        $thirdPartyPayment = !empty($data['third_party_payment']);
        $thirdPartyReported = $thirdPartyPayment && !empty($data['third_party_reported_to_sunat']);

        $bankAccountId = !empty($data['bank_account_id']) ? (int) $data['bank_account_id'] : null;
        $bankId = !empty($data['bank_id']) ? (int) $data['bank_id'] : null;

        if ($bankAccountId !== null && $bankId === null) {
            $bankAccount = BankAccount::find($bankAccountId);
            if ($bankAccount) {
                $bankId = (int) $bankAccount->bank_id;
            }
        }

        return PaymentEvidence::updateOrCreate(
            [
                'payable_type' => get_class($payable),
                'payable_id' => $payable->getKey(),
            ],
            [
                'sunat_payment_code' => $sunatCode,
                'operation_number' => $this->nullableTrim($data['operation_number'] ?? null),
                'bank_id' => $bankId,
                'bank_account_id' => $bankAccountId,
                'operation_date' => $operationDate,
                'payer_or_beneficiary_document' => $this->nullableTrim($data['payer_or_beneficiary_document'] ?? null),
                'payer_or_beneficiary_name' => $this->nullableTrim($data['payer_or_beneficiary_name'] ?? null),
                'third_party_payment' => $thirdPartyPayment,
                'third_party_reported_to_sunat' => $thirdPartyReported,
                'bancarization_exception' => $exception,
            ]
        );
    }

    public function serializeEvidence(PaymentEvidence $evidence): array
    {
        return [
            'id' => (int) $evidence->id,
            'payable_type' => $evidence->payable_type,
            'payable_id' => (int) $evidence->payable_id,
            'sunat_payment_code' => $evidence->sunat_payment_code,
            'operation_number' => $evidence->operation_number,
            'bank_id' => $evidence->bank_id ? (int) $evidence->bank_id : null,
            'bank_account_id' => $evidence->bank_account_id ? (int) $evidence->bank_account_id : null,
            'operation_date' => $evidence->operation_date ? $evidence->operation_date->format('Y-m-d') : null,
            'payer_or_beneficiary_document' => $evidence->payer_or_beneficiary_document,
            'payer_or_beneficiary_name' => $evidence->payer_or_beneficiary_name,
            'third_party_payment' => (bool) $evidence->third_party_payment,
            'third_party_reported_to_sunat' => (bool) $evidence->third_party_reported_to_sunat,
            'bancarization_exception' => $evidence->bancarization_exception,
        ];
    }

    private function extractPayableContext(Model $payable): array
    {
        if ($payable instanceof TreasuryMovement) {
            $bankAccountId = null;
            $bankId = null;
            if ($payable->destination_type === BankAccount::class && $payable->destination_id) {
                $bankAccountId = (int) $payable->destination_id;
                $bankAccount = BankAccount::find($bankAccountId);
                if ($bankAccount) {
                    $bankId = (int) $bankAccount->bank_id;
                }
            }

            $method = $payable->payment_method_type;
            $isCash = $method ? (bool) $method->is_cash : ($payable->destination_type !== BankAccount::class);
            $sunatCode = $payable->sunat_payment_code ?: ($method ? $method->sunat_code : null);

            $docNumber = $payable->counterparty_document;
            $docName = $payable->counterparty_name;
            if ((!$docNumber || !$docName) && $payable->counterparty_person_id) {
                $person = $payable->counterparty;
                if ($person) {
                    $docNumber = $docNumber ?: $person->number;
                    $docName = $docName ?: $person->name;
                }
            }

            return [
                'amount' => (float) $payable->amount,
                'currency_type_id' => (string) ($payable->currency_type_id ?: 'PEN'),
                'exchange_rate_sale' => $payable->exchange_rate_sale ? (float) $payable->exchange_rate_sale : null,
                'is_cash' => $isCash,
                'defaults' => [
                    'operation_date' => $this->normalizeDate($payable->date) ?: date('Y-m-d'),
                    'operation_number' => $payable->operation_number,
                    'sunat_payment_code' => $sunatCode,
                    'bank_id' => $bankId,
                    'bank_account_id' => $bankAccountId,
                    'payer_or_beneficiary_document' => $docNumber,
                    'payer_or_beneficiary_name' => $docName,
                ],
            ];
        }

        // Legacy payment models
        $rawDate = $payable->getAttribute('date_of_payment') ?: $payable->getAttribute('date');
        $operationDate = $this->normalizeDate($rawDate) ?: date('Y-m-d');
        $operationNumber = $payable->getAttribute('reference');
        $amount = (float) ($payable->getAttribute('payment') ?? 0.0);

        $sunatCode = null;
        $isCash = false;

        if ($payable instanceof ExpensePayment) {
            // expense_method_type_id == 1 is "Caja general" (cash) in Expense module
            $isCash = ((int) $payable->expense_method_type_id === 1);
            $sunatCode = $isCash ? '008' : '003';
        } elseif (method_exists($payable, 'payment_method_type')) {
            $method = $payable->payment_method_type;
            if ($method) {
                $sunatCode = $method->sunat_code;
                $isCash = (bool) $method->is_cash || $method->id === '01';
            }
        }

        $bankAccountId = null;
        $bankId = null;
        if (method_exists($payable, 'global_payment')) {
            try {
                $global = $payable->global_payment;
                if ($global && $global->destination_type === BankAccount::class && $global->destination_id) {
                    $bankAccountId = (int) $global->destination_id;
                    $bankAccount = BankAccount::find($bankAccountId);
                    if ($bankAccount) {
                        $bankId = (int) $bankAccount->bank_id;
                    }
                }
            } catch (\Throwable $e) {
                // Ignore if global_payments table is absent in isolated tests.
            }
        }

        $currencyTypeId = 'PEN';
        $exchangeRateSale = null;
        $docNumber = null;
        $docName = null;

        if (method_exists($payable, 'associated_record_payment')) {
            try {
                $parent = $payable->associated_record_payment;
                if ($parent) {
                    if (!empty($parent->currency_type_id)) {
                        $currencyTypeId = (string) $parent->currency_type_id;
                    }
                    if (!empty($parent->exchange_rate_sale)) {
                        $exchangeRateSale = (float) $parent->exchange_rate_sale;
                    }
                    [$docNumber, $docName] = $this->extractCounterpartyFromParent($parent);
                }
            } catch (\Throwable $e) {
                // Ignore if parent relation table is absent in isolated tests.
            }
        }

        return [
            'amount' => $amount,
            'currency_type_id' => $currencyTypeId,
            'exchange_rate_sale' => $exchangeRateSale,
            'is_cash' => $isCash,
            'defaults' => [
                'operation_date' => $operationDate,
                'operation_number' => $operationNumber,
                'sunat_payment_code' => $sunatCode,
                'bank_id' => $bankId,
                'bank_account_id' => $bankAccountId,
                'payer_or_beneficiary_document' => $docNumber,
                'payer_or_beneficiary_name' => $docName,
            ],
        ];
    }

    private function extractCounterpartyFromParent(Model $parent): array
    {
        foreach (['customer', 'supplier', 'person'] as $attr) {
            $value = $parent->getAttribute($attr);
            if (is_object($value)) {
                return [
                    $value->number ?? null,
                    $value->name ?? null,
                ];
            }
            if (is_array($value)) {
                return [
                    $value['number'] ?? null,
                    $value['name'] ?? null,
                ];
            }
        }

        return [null, null];
    }

    private function normalizeDate($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        try {
            return Carbon::parse((string) $value)->format('Y-m-d');
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
}
