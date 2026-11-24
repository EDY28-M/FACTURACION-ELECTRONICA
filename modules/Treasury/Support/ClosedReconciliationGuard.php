<?php

namespace Modules\Treasury\Support;

use App\Models\Tenant\DocumentPayment;
use App\Models\Tenant\PurchasePayment;
use App\Models\Tenant\SaleNotePayment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Expense\Models\ExpensePayment;
use Modules\Finance\Models\GlobalPayment;
use Modules\Finance\Models\IncomePayment;
use Modules\Sale\Models\ContractPayment;
use Modules\Sale\Models\QuotationPayment;
use Modules\Sale\Models\TechnicalServicePayment;

/**
 * Single place that protects legacy Finance payments matched in a CLOSED bank reconciliation.
 *
 * A payment (document, sale note, purchase, expense, quotation, contract, income, technical service) whose
 * global_payments row backs an active match inside a closed bank statement cannot be deleted, nor have its amount,
 * date or destination changed: the closed reconciliation (and its snapshot) would silently stop matching the books.
 * The user must reopen the reconciliation first.
 *
 * Wiring:
 *  - register() adds `deleting` / `updating` model listeners (called from GlobalPaymentServiceProvider BEFORE its own
 *    cascade listener, so the guard still sees the global_payments row that cascade deletes).
 *  - FinanceTrait::createGlobalPayment() / deleteAllPayments() call the assert methods directly because they remove
 *    global_payments with query-builder deletes that never fire model events.
 *
 * Tenants without the reconciliation tables (or SQLite characterization schemas) are unaffected: every check is
 * guarded with a per-database hasTable() cache and fails open on schema errors.
 *
 * Known trade-off (kept on purpose): the legacy edit flows of a parent document (purchase, expense, sale note, contract,
 * quotation, CPE...) delete and recreate ALL its payments. The guard therefore blocks ANY edit of a parent that has a
 * payment matched in a closed statement, even when the edit does not touch the payments. The protection is correct
 * (a recreated payment would silently orphan the closed match) and the legacy flows are not redesigned here; instead
 * the refusal carries an actionable message (MESSAGE_PARENT_EDIT) telling the user to reopen the reconciliation.
 *
 * Not guarded on purpose: voiding a whole document (state 11) goes through SUNAT's voiding flow that cannot be
 * blocked locally; the reconciliation reports those matches as stale ("missing") instead.
 */
class ClosedReconciliationGuard
{
    public const MESSAGE = 'El pago está conciliado en un extracto cerrado; reabra la conciliación primero.';

    /** Shown when editing a parent document is refused because one of its payments is matched in a closed statement. */
    public const MESSAGE_PARENT_EDIT = 'El documento tiene pagos conciliados en un extracto cerrado; para editarlo reabra la conciliación.';

    /** Payment model classes that own a global_payments row matchable by a statement line. */
    public const PAYMENT_MODELS = [
        DocumentPayment::class,
        SaleNotePayment::class,
        PurchasePayment::class,
        QuotationPayment::class,
        ExpensePayment::class,
        ContractPayment::class,
        IncomePayment::class,
        TechnicalServicePayment::class,
    ];

    /** @var array<string, bool> */
    private static $tablesReady = [];

    public static function register(): void
    {
        foreach (self::PAYMENT_MODELS as $modelClass) {
            $modelClass::deleting(function ($payment) {
                self::assertPaymentDeletable($payment);
            });

            $modelClass::updating(function ($payment) {
                self::assertPaymentUpdatable($payment);
            });
        }
    }

    public static function assertPaymentDeletable(Model $payment, bool $parentEdit = false): void
    {
        if (self::isPaymentMatchedInClosedStatement(get_class($payment), $payment->getKey())) {
            throw self::refusal($parentEdit ? self::MESSAGE_PARENT_EDIT : self::MESSAGE);
        }
    }

    /**
     * Refuses changes to amount or date of a matched payment (other attributes are unrelated to the match).
     */
    public static function assertPaymentUpdatable(Model $payment): void
    {
        if (!self::matchedAttributesChanged($payment)) {
            return;
        }

        if (self::isPaymentMatchedInClosedStatement(get_class($payment), $payment->getKey())) {
            throw self::refusal();
        }
    }

    /**
     * True when amount or date really changed. Compared by value (2-decimal amount, Y-m-d date) instead of with
     * isDirty(): a form posting 100 for a stored "100.00" is dirty for Eloquent but is not a change.
     */
    private static function matchedAttributesChanged(Model $payment): bool
    {
        if (!$payment->isDirty('payment') && !$payment->isDirty('date_of_payment')) {
            return false;
        }

        if ($payment->isDirty('payment')
            && round((float) $payment->getAttribute('payment'), 2) !== round((float) $payment->getOriginal('payment'), 2)) {
            return true;
        }

        if ($payment->isDirty('date_of_payment')) {
            $toDay = function ($value): string {
                if ($value instanceof \DateTimeInterface) {
                    return $value->format('Y-m-d');
                }

                return substr((string) $value, 0, 10);
            };

            return $toDay($payment->getAttribute('date_of_payment')) !== $toDay($payment->getOriginal('date_of_payment'));
        }

        return false;
    }

    /**
     * Used when a payment's global_payments row is about to be replaced: refuses a different destination and tells the
     * caller (true) to keep the existing row when the destination is unchanged.
     */
    public static function shouldKeepGlobalPayment(Model $payment, $destinationId, string $destinationType): bool
    {
        $rows = self::closedMatchedGlobalPayments(get_class($payment), $payment->getKey());
        if (empty($rows)) {
            return false;
        }

        foreach ($rows as $row) {
            if ((string) $row->destination_type !== $destinationType || (string) $row->destination_id !== (string) $destinationId) {
                throw self::refusal();
            }
        }

        return true;
    }

    public static function isPaymentMatchedInClosedStatement(string $paymentClass, $paymentId): bool
    {
        return !empty(self::closedMatchedGlobalPayments($paymentClass, $paymentId));
    }

    /**
     * @return array<int, object> global_payments rows (id, destination_type, destination_id) backing an active match
     *                            in a closed statement
     */
    private static function closedMatchedGlobalPayments(string $paymentClass, $paymentId): array
    {
        if ($paymentId === null || !self::tablesReady()) {
            return [];
        }

        try {
            return DB::connection('tenant')
                ->table('global_payments as gp')
                ->join('bank_statement_line_matches as m', function ($join) {
                    $join->on('m.matchable_id', '=', 'gp.id')
                        ->where('m.matchable_type', '=', GlobalPayment::class)
                        ->whereNull('m.released_at');
                })
                ->join('bank_statement_lines as l', 'l.id', '=', 'm.bank_statement_line_id')
                ->join('bank_statements as s', 's.id', '=', 'l.bank_statement_id')
                ->where('s.status', 'closed')
                ->where('gp.payment_type', $paymentClass)
                ->where('gp.payment_id', $paymentId)
                ->get(['gp.id', 'gp.destination_type', 'gp.destination_id'])
                ->all();
        } catch (\Throwable $e) {
            // Fail open: a schema problem must never block legacy payments.
            return [];
        }
    }

    private static function tablesReady(): bool
    {
        try {
            $connection = DB::connection('tenant');
            $key = (string) $connection->getDatabaseName();
            if (!array_key_exists($key, self::$tablesReady)) {
                $schema = $connection->getSchemaBuilder();
                self::$tablesReady[$key] = $schema->hasTable('global_payments')
                    && $schema->hasTable('bank_statement_line_matches')
                    && $schema->hasTable('bank_statement_lines')
                    && $schema->hasTable('bank_statements');
            }

            return self::$tablesReady[$key];
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Test seam: forget cached schema probes. */
    public static function flushCache(): void
    {
        self::$tablesReady = [];
    }

    private static function refusal(string $message = self::MESSAGE): ValidationException
    {
        return ValidationException::withMessages(['message' => [$message]]);
    }
}
