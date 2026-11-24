<?php

namespace Tests\Unit\Treasury;

use App\Models\Tenant\BankAccount;
use App\Models\Tenant\DocumentPayment;
use App\Models\Tenant\PurchasePayment;
use App\Models\Tenant\SaleNote;
use App\Models\Tenant\SaleNotePayment;
use Illuminate\Validation\ValidationException;
use Modules\Finance\Models\GlobalPayment;
use Modules\Finance\Traits\FinanceTrait;
use Modules\Treasury\Support\ClosedReconciliationGuard;
use PHPUnit\Framework\TestCase;

/**
 * Legacy Finance payments matched in a CLOSED bank reconciliation cannot be deleted nor have amount, date or
 * destination changed; unrelated payments and draft statements are unaffected.
 */
class ClosedReconciliationGuardTest extends TestCase
{
    use Phase4Harness;

    private $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootHarness();
        ClosedReconciliationGuard::flushCache();
        ClosedReconciliationGuard::register();

        $this->subject = new class {
            use FinanceTrait;
        };
    }

    protected function tearDown(): void
    {
        ClosedReconciliationGuard::flushCache();
        $this->tearDownHarness();
        parent::tearDown();
    }

    /** @return array{payment: int, global_payment: int, statement: int} */
    private function matchedPayment(string $statementStatus, float $amount = 100.00, string $date = '2026-11-05'): array
    {
        $documentId = $this->insertDocument(['date_of_issue' => $date, 'total' => $amount]);
        $paymentId = (int) $this->db->table('document_payments')->insertGetId([
            'document_id' => $documentId, 'payment' => $amount, 'date_of_payment' => $date,
        ]);
        $globalPaymentId = (int) $this->db->table('global_payments')->insertGetId([
            'destination_id' => 1, 'destination_type' => BankAccount::class,
            'payment_id' => $paymentId, 'payment_type' => DocumentPayment::class,
        ]);

        $statementId = (int) $this->db->table('bank_statements')->insertGetId([
            'bank_account_id' => 1, 'period_from' => '2026-11-01', 'period_to' => '2026-11-30',
            'opening_balance' => 0, 'closing_balance' => $amount, 'status' => $statementStatus, 'imported_by' => 1,
        ]);
        $lineId = (int) $this->db->table('bank_statement_lines')->insertGetId([
            'bank_statement_id' => $statementId, 'line_no' => 1, 'date' => $date, 'description' => 'Abono', 'amount' => $amount, 'status' => 'matched',
        ]);
        $this->db->table('bank_statement_line_matches')->insert([
            'bank_statement_line_id' => $lineId, 'matchable_type' => GlobalPayment::class, 'matchable_id' => $globalPaymentId,
            'amount' => $amount, 'item_date' => $date, 'active_match_key' => GlobalPayment::class . ':' . $globalPaymentId,
        ]);

        return ['payment' => $paymentId, 'global_payment' => $globalPaymentId, 'statement' => $statementId];
    }

    private function assertRefused(callable $action, string $message = ClosedReconciliationGuard::MESSAGE): void
    {
        try {
            $action();
            $this->fail('Expected the closed reconciliation guard to refuse the operation.');
        } catch (ValidationException $e) {
            $this->assertSame($message, $e->errors()['message'][0]);
        }
    }

    public function test_deleting_a_payment_matched_in_a_closed_statement_is_refused(): void
    {
        $matched = $this->matchedPayment('closed');

        $this->assertRefused(function () use ($matched) {
            DocumentPayment::findOrFail($matched['payment'])->delete();
        });

        $this->assertSame(1, $this->db->table('document_payments')->where('id', $matched['payment'])->count());
        $this->assertSame(1, $this->db->table('global_payments')->where('id', $matched['global_payment'])->count());
    }

    public function test_changing_amount_or_date_of_a_matched_payment_is_refused(): void
    {
        $matched = $this->matchedPayment('closed');

        $this->assertRefused(function () use ($matched) {
            $payment = DocumentPayment::findOrFail($matched['payment']);
            $payment->payment = 120;
            $payment->save();
        });
        $this->assertRefused(function () use ($matched) {
            $payment = DocumentPayment::findOrFail($matched['payment']);
            $payment->date_of_payment = '2026-11-06';
            $payment->save();
        });

        $row = $this->db->table('document_payments')->where('id', $matched['payment'])->first();
        $this->assertEquals(100.00, $row->payment);
        $this->assertSame('2026-11-05', substr($row->date_of_payment, 0, 10));
    }

    public function test_saving_the_same_amount_and_unrelated_fields_is_allowed(): void
    {
        $matched = $this->matchedPayment('closed');

        $payment = DocumentPayment::findOrFail($matched['payment']);
        $payment->payment = 100;           // same amount posted by a form
        $payment->reference = 'OP-123';    // unrelated attribute
        $payment->save();

        $this->assertSame('OP-123', $this->db->table('document_payments')->where('id', $matched['payment'])->value('reference'));
    }

    public function test_destination_change_is_refused_and_same_destination_keeps_the_global_payment(): void
    {
        $matched = $this->matchedPayment('closed');
        $payment = DocumentPayment::findOrFail($matched['payment']);

        $this->assertRefused(function () use ($payment) {
            $this->subject->createGlobalPayment($payment, ['payment_destination_id' => 2]);
        });
        $this->assertSame(1, $this->db->table('global_payments')->where('id', $matched['global_payment'])->count());

        // Same destination: the global payment (and therefore the closed match) is left untouched.
        $this->subject->createGlobalPayment($payment, ['payment_destination_id' => 1]);
        $this->assertSame(
            [$matched['global_payment']],
            $this->db->table('global_payments')->where('payment_id', $matched['payment'])->pluck('id')->map(function ($id) {
                return (int) $id;
            })->all()
        );
    }

    public function test_delete_all_payments_is_atomic_when_one_payment_is_matched(): void
    {
        $matched = $this->matchedPayment('closed');
        $free = $this->matchedPayment('draft', 50.00, '2026-11-07');

        $payments = DocumentPayment::whereIn('id', [$free['payment'], $matched['payment']])->get();
        // B-W2: editing a parent document (delete + recreate of its payments) gets an actionable message.
        $this->assertRefused(function () use ($payments) {
            $this->subject->deleteAllPayments($payments);
        }, ClosedReconciliationGuard::MESSAGE_PARENT_EDIT);

        $this->assertSame(2, $this->db->table('document_payments')->count());
        $this->assertSame(2, $this->db->table('global_payments')->count());
    }

    public function test_parent_edit_message_tells_the_user_to_reopen_the_reconciliation(): void
    {
        $this->assertSame(
            'El documento tiene pagos conciliados en un extracto cerrado; para editarlo reabra la conciliación.',
            ClosedReconciliationGuard::MESSAGE_PARENT_EDIT
        );
    }

    public function test_unrelated_released_and_draft_matches_are_unaffected(): void
    {
        $draft = $this->matchedPayment('draft', 70.00, '2026-11-08');
        $released = $this->matchedPayment('closed', 80.00, '2026-11-09');
        $this->db->table('bank_statement_line_matches')->where('matchable_id', $released['global_payment'])
            ->update(['released_at' => '2026-11-10 10:00:00', 'active_match_key' => null]);
        $plain = (int) $this->db->table('document_payments')->insertGetId([
            'document_id' => $this->insertDocument(), 'payment' => 10, 'date_of_payment' => '2026-11-11',
        ]);

        $payment = DocumentPayment::findOrFail($draft['payment']);
        $payment->payment = 75;
        $payment->save();
        DocumentPayment::findOrFail($released['payment'])->delete();
        DocumentPayment::findOrFail($plain)->delete();

        $this->assertSame(1, $this->db->table('document_payments')->count());
        $this->assertEquals(75.00, $this->db->table('document_payments')->where('id', $draft['payment'])->value('payment'));
    }

    public function test_other_payment_types_with_the_same_numeric_id_are_not_confused(): void
    {
        $matched = $this->matchedPayment('closed');
        $purchaseId = $this->insertPurchase();
        $purchasePaymentId = (int) $this->db->table('purchase_payments')->insertGetId([
            'id' => $matched['payment'], 'purchase_id' => $purchaseId, 'payment' => 30, 'date_of_payment' => '2026-11-05',
        ]);

        $payment = PurchasePayment::findOrFail($purchasePaymentId);
        $payment->payment = 31;
        $payment->save();

        $this->assertEquals(31.00, $this->db->table('purchase_payments')->where('id', $purchasePaymentId)->value('payment'));
    }

    public function test_tenants_without_reconciliation_tables_are_unaffected(): void
    {
        $this->db->statement('drop table bank_statement_line_matches');
        ClosedReconciliationGuard::flushCache();

        $paymentId = (int) $this->db->table('document_payments')->insertGetId([
            'document_id' => $this->insertDocument(), 'payment' => 10, 'date_of_payment' => '2026-11-11',
        ]);
        DocumentPayment::findOrFail($paymentId)->delete();

        $this->assertSame(0, $this->db->table('document_payments')->count());
    }

    /**
     * Api\SaleNoteController::store() used `$saleNote->payments()->delete()`: a query-builder delete that fires no
     * model events and therefore skipped this guard. It now deletes model by model.
     */
    public function test_sale_note_payments_deleted_model_by_model_are_guarded_while_a_query_delete_is_not(): void
    {
        $saleNoteId = (int) $this->db->table('sale_notes')->insertGetId([
            'user_id' => 1, 'customer_id' => 1, 'establishment_id' => 1, 'state_type_id' => '01', 'series' => 'NV01', 'number' => '1',
            'date_of_issue' => '2026-11-05', 'total' => 100, 'currency_type_id' => 'PEN', 'exchange_rate_sale' => 1,
        ]);
        $paymentId = (int) $this->db->table('sale_note_payments')->insertGetId([
            'sale_note_id' => $saleNoteId, 'payment' => 100, 'date_of_payment' => '2026-11-05',
        ]);
        $globalPaymentId = (int) $this->db->table('global_payments')->insertGetId([
            'destination_id' => 1, 'destination_type' => BankAccount::class,
            'payment_id' => $paymentId, 'payment_type' => SaleNotePayment::class,
        ]);
        $statementId = (int) $this->db->table('bank_statements')->insertGetId([
            'bank_account_id' => 1, 'period_from' => '2026-11-01', 'period_to' => '2026-11-30',
            'opening_balance' => 0, 'closing_balance' => 100, 'status' => 'closed', 'imported_by' => 1,
        ]);
        $lineId = (int) $this->db->table('bank_statement_lines')->insertGetId([
            'bank_statement_id' => $statementId, 'line_no' => 1, 'date' => '2026-11-05', 'description' => 'Abono', 'amount' => 100, 'status' => 'matched',
        ]);
        $this->db->table('bank_statement_line_matches')->insert([
            'bank_statement_line_id' => $lineId, 'matchable_type' => GlobalPayment::class, 'matchable_id' => $globalPaymentId,
            'amount' => 100, 'item_date' => '2026-11-05', 'active_match_key' => GlobalPayment::class . ':' . $globalPaymentId,
        ]);

        // What the controller does now.
        $this->assertRefused(function () use ($saleNoteId) {
            foreach ($this->saleNote($saleNoteId)->payments()->get() as $payment) {
                $payment->delete();
            }
        });
        $this->assertSame(1, $this->db->table('sale_note_payments')->where('id', $paymentId)->count());

        // The previous behavior, kept here to document why it was a bypass.
        $this->saleNote($saleNoteId)->payments()->delete();
        $this->assertSame(0, $this->db->table('sale_note_payments')->where('id', $paymentId)->count());
    }

    /** Bare model (no default eager loads) pointing at an existing row: only its payments relation is used. */
    private function saleNote(int $id): SaleNote
    {
        $saleNote = new SaleNote();
        $saleNote->id = $id;
        $saleNote->exists = true;

        return $saleNote;
    }

    public function test_no_raw_query_delete_remains_on_payment_relations_in_app_and_modules(): void
    {
        $root = __DIR__ . '/../../..';
        $offenders = [];
        foreach (['app', 'modules'] as $dir) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                foreach (file($file->getPathname()) as $number => $line) {
                    $code = ltrim($line);
                    if (strpos($code, '//') === 0 || strpos($code, '*') === 0 || strpos($code, '#') === 0) {
                        continue;
                    }
                    if (preg_match('/(?<![A-Za-z0-9_])payments?\(\)\s*->\s*delete\(/', $line)) {
                        $offenders[] = substr($file->getPathname(), strlen($root) + 1) . ':' . ($number + 1);
                    }
                }
            }
        }

        $this->assertSame([], $offenders, 'Query-builder deletes on payment relations bypass ClosedReconciliationGuard.');
    }
}
