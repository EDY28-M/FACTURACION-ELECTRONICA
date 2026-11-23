<?php

namespace Tests\Unit\Treasury;

use App\Models\Tenant\BankAccount;
use App\Models\Tenant\DocumentPayment;
use App\Models\Tenant\PurchasePayment;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use Modules\Finance\Models\GlobalPayment;
use Modules\Treasury\Models\BankStatement;
use Modules\Treasury\Models\BankStatementLine;
use Modules\Treasury\Models\BankStatementLineMatch;
use Modules\Treasury\Models\TreasuryMovement;
use Modules\Treasury\Services\ReconciliationService;
use PHPUnit\Framework\TestCase;

/**
 * Phase 5: bank reconciliation (matching, one-to-many, create-from-line, closing, reopening, voiding guards, access).
 */
class ReconciliationServiceTest extends TestCase
{
    use Phase4Harness;

    private const ADMIN = ['is_admin' => true, 'user_id' => 1, 'establishment_id' => 1];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootHarness();
    }

    protected function tearDown(): void
    {
        $this->tearDownHarness();
        parent::tearDown();
    }

    private function service(): ReconciliationService
    {
        return new ReconciliationService($this->makeMovementService(), $this->makeBalanceService());
    }

    private function user(int $establishmentId): array
    {
        return ['is_admin' => false, 'user_id' => 2, 'establishment_id' => $establishmentId];
    }

    /**
     * SQLite stores model dates as "Y-m-d 00:00:00" while MySQL `date` columns store "Y-m-d". The balance service
     * compares with plain strings, so normalize like a real DATE column before reading balances.
     */
    private function normalizeDates(): void
    {
        $this->db->statement("update treasury_movements set date = substr(date, 1, 10)");
    }

    private function movement(string $kind, string $date, float $amount, ?string $operation = null, int $accountId = 1, string $description = 'Mov'): TreasuryMovement
    {
        $conceptId = $kind === 'income' ? 4 : 5;
        $result = $this->makeMovementService()->createManual([
            'kind' => $kind,
            'date' => $date,
            'amount' => $amount,
            'destination_type' => 'bank_account',
            'destination_id' => $accountId,
            'treasury_concept_id' => $conceptId,
            'description' => $description,
            'operation_number' => $operation,
            'establishment_id' => 1,
        ], 1);
        $this->normalizeDates();

        return $result['movement'];
    }

    private function documentPayment(float $amount, string $date, ?string $reference = null, int $accountId = 1, string $currency = 'PEN', float $rate = 1.0): int
    {
        $documentId = $this->insertDocument(['total' => $amount, 'currency_type_id' => $currency, 'exchange_rate_sale' => $rate]);
        $paymentId = (int) $this->db->table('document_payments')->insertGetId([
            'document_id' => $documentId, 'payment' => $amount, 'date_of_payment' => $date, 'reference' => $reference,
        ]);

        return (int) $this->db->table('global_payments')->insertGetId([
            'destination_id' => $accountId, 'destination_type' => BankAccount::class,
            'payment_id' => $paymentId, 'payment_type' => DocumentPayment::class, 'user_id' => 1,
        ]);
    }

    private function purchasePayment(float $amount, string $date, ?string $reference = null, int $accountId = 1): int
    {
        $purchaseId = $this->insertPurchase(['total' => $amount]);
        $paymentId = (int) $this->db->table('purchase_payments')->insertGetId([
            'purchase_id' => $purchaseId, 'payment' => $amount, 'date_of_payment' => $date, 'reference' => $reference,
        ]);

        return (int) $this->db->table('global_payments')->insertGetId([
            'destination_id' => $accountId, 'destination_type' => BankAccount::class,
            'payment_id' => $paymentId, 'payment_type' => PurchasePayment::class, 'user_id' => 1,
        ]);
    }

    /**
     * @param array<int, array{0: string, 1: string, 2: float, 3?: ?string}> $lines [date, description, amount, reference]
     */
    private function statement(array $lines, float $opening, string $from = '2026-11-01', string $to = '2026-11-20', int $accountId = 1, array $scope = self::ADMIN, array $extraMeta = []): BankStatement
    {
        $parsed = [];
        $net = 0.0;
        foreach ($lines as $i => $line) {
            $parsed[] = [
                'line_no' => $i + 1,
                'date' => $line[0],
                'description' => $line[1],
                'reference' => $line[3] ?? null,
                'amount' => $line[2],
            ];
            $net += $line[2];
        }

        return $this->service()->createStatement($accountId, array_merge([
            'period_from' => $from,
            'period_to' => $to,
            'opening_balance' => $opening,
            'closing_balance' => round($opening + $net, 2),
        ], $extraMeta), $parsed, 'extracto.csv', 'bank_statements/x.csv', 1, $scope);
    }

    private function lineId(BankStatement $statement, int $lineNo): int
    {
        return (int) BankStatementLine::where('bank_statement_id', $statement->id)->where('line_no', $lineNo)->value('id');
    }

    private function line(BankStatement $statement, int $lineNo): BankStatementLine
    {
        return BankStatementLine::where('bank_statement_id', $statement->id)->where('line_no', $lineNo)->firstOrFail();
    }

    private function assertValidationMessage(callable $callback, string $needle): void
    {
        try {
            $callback();
            $this->fail('Expected a ValidationException containing: ' . $needle);
        } catch (ValidationException $e) {
            $all = implode(' | ', array_merge(...array_values($e->errors())));
            $this->assertStringContainsString($needle, $all);
        }
    }

    // ---------------------------------------------------------------------------------------------------------
    // Import
    // ---------------------------------------------------------------------------------------------------------

    public function test_import_rejects_overlapping_period_and_allows_reimport_after_discard(): void
    {
        $first = $this->statement([['2026-11-05', 'ABONO', 100.00]], 5000.00);

        $this->assertValidationMessage(function () {
            $this->statement([['2026-11-20', 'OTRO', 50.00]], 5000.00, '2026-11-15', '2026-11-30');
        }, 'se superpone');

        // A different account with the same period is independent.
        $other = $this->statement([['2026-11-10', 'OTRO', 50.00]], 100.00, '2026-11-01', '2026-11-20', 2);
        $this->assertSame(2, (int) $other->bank_account_id);

        // Adjacent (non overlapping) period is accepted.
        $next = $this->statement([['2026-11-25', 'SIG', 10.00]], 5100.00, '2026-11-21', '2026-11-30');
        $this->assertNotNull($next->id);

        $path = $this->service()->discardStatement((int) $first->id, 1, self::ADMIN);
        $this->assertSame('bank_statements/x.csv', $path);
        $this->assertSame(0, BankStatementLine::where('bank_statement_id', $first->id)->count());

        $again = $this->statement([['2026-11-05', 'ABONO', 100.00]], 5000.00);
        $this->assertNotNull($again->id);
    }

    public function test_import_validates_period_lines_and_balances(): void
    {
        $this->assertValidationMessage(function () {
            $this->statement([['2026-12-05', 'FUERA', 100.00]], 5000.00);
        }, 'fuera del período');

        $this->assertValidationMessage(function () {
            $this->statement([['2026-11-05', 'A', 100.00]], 5000.00, '2026-11-01', '2026-11-20', 1, self::ADMIN, ['closing_balance' => 9999.00]);
        }, 'no coincide con el saldo final');

        $accepted = $this->statement([['2026-11-05', 'A', 100.00]], 5000.00, '2026-11-01', '2026-11-20', 1, self::ADMIN, ['closing_balance' => 9999.00, 'accept_unbalanced' => true]);
        $this->assertNotNull($accepted->id);

        $this->assertValidationMessage(function () {
            $this->service()->createStatement(1, ['period_from' => '2026-12-01', 'period_to' => '2026-12-10', 'opening_balance' => 0, 'closing_balance' => 0], [], null, null, 1, self::ADMIN);
        }, 'no contiene movimientos');
    }

    // ---------------------------------------------------------------------------------------------------------
    // Automatic matching
    // ---------------------------------------------------------------------------------------------------------

    public function test_auto_match_uses_amount_date_window_and_reference(): void
    {
        $matchedMovement = $this->movement('income', '2026-11-03', 1000.00, 'OP123456');
        $gp = $this->documentPayment(300.00, '2026-11-05', 'REF-9988');
        $purchaseGp = $this->purchasePayment(200.00, '2026-11-07');
        $tooFar = $this->movement('expense', '2026-11-01', 40.00); // 5 days away from the line: outside the 3-day window
        $voided = $this->movement('expense', '2026-11-09', 77.00);
        $this->makeMovementService()->void((int) $voided->id, 'anulado para la prueba', 1);
        $otherAccount = $this->movement('expense', '2026-11-10', 55.00, null, 2);

        $statement = $this->statement([
            ['2026-11-04', 'ABONO TRANSFERENCIA', 1000.00, 'OP123456'],   // 1
            ['2026-11-06', 'TRANSF REF9988 CLIENTE', 300.00],             // 2: reference in the description
            ['2026-11-07', 'PAGO PROVEEDOR', -200.00],                    // 3: outflow, sign from the purchase payment
            ['2026-11-06', 'CARGO FUERA DE VENTANA', -40.00],             // 4: 5 days from the movement
            ['2026-11-09', 'CARGO ANULADO', -77.00],                      // 5: only a voided item has this amount
            ['2026-11-10', 'CARGO OTRA CUENTA', -55.00],                  // 6: only account 2 has this amount
            ['2026-11-11', 'SIN CONTRAPARTE', -13.00],                    // 7
        ], 5000.00);

        $result = $this->service()->autoMatch((int) $statement->id, 1, self::ADMIN);

        $this->assertSame(3, $result['matched']);
        $this->assertSame(4, $result['unmatched']);
        $this->assertSame('matched', $this->line($statement, 1)->status);
        $this->assertSame('matched', $this->line($statement, 2)->status);
        $this->assertSame('matched', $this->line($statement, 3)->status);
        foreach ([4, 5, 6, 7] as $lineNo) {
            $this->assertSame('unmatched', $this->line($statement, $lineNo)->status, "line {$lineNo}");
        }

        $this->assertSame((int) $this->lineId($statement, 1), (int) TreasuryMovement::find($matchedMovement->id)->bank_statement_line_id);
        $this->assertNull(TreasuryMovement::find($tooFar->id)->bank_statement_line_id);
        $this->assertNull(TreasuryMovement::find($otherAccount->id)->bank_statement_line_id);

        $keys = BankStatementLineMatch::whereNull('released_at')->pluck('active_match_key')->all();
        $this->assertContains(GlobalPayment::class . ':' . $gp, $keys);
        $this->assertContains(GlobalPayment::class . ':' . $purchaseGp, $keys);

        $purchaseMatch = BankStatementLineMatch::where('matchable_type', GlobalPayment::class)->where('matchable_id', $purchaseGp)->first();
        $this->assertSame(-200.0, $purchaseMatch->amount);
    }

    public function test_auto_match_leaves_ambiguous_lines_and_resolves_them_with_reference(): void
    {
        $a = $this->movement('expense', '2026-11-04', 100.00, 'CHQ00111');
        $b = $this->movement('expense', '2026-11-05', 100.00, 'CHQ00222');

        $statement = $this->statement([
            ['2026-11-05', 'CARGO GENERICO', -100.00],                       // 1: two equal candidates, no reference -> ambiguous
            ['2026-11-05', 'CHEQUE 00222 COBRADO', -100.00, 'CHQ00222'],     // 2: reference picks movement b
        ], 5000.00);

        $result = $this->service()->autoMatch((int) $statement->id, 1, self::ADMIN);

        // Lines are processed by date then number: line 1 first, but ambiguity must not consume a candidate.
        $this->assertSame(1, $result['ambiguous']);
        $this->assertSame('unmatched', $this->line($statement, 1)->status);
        $this->assertSame('matched', $this->line($statement, 2)->status);
        $this->assertSame((int) $this->lineId($statement, 2), (int) TreasuryMovement::find($b->id)->bank_statement_line_id);
        $this->assertNull(TreasuryMovement::find($a->id)->bank_statement_line_id);
    }

    public function test_auto_match_never_double_matches_one_item_to_two_lines(): void
    {
        $this->movement('expense', '2026-11-05', 80.00);

        $statement = $this->statement([
            ['2026-11-05', 'CARGO A', -80.00],
            ['2026-11-05', 'CARGO B', -80.00],
        ], 5000.00);

        $result = $this->service()->autoMatch((int) $statement->id, 1, self::ADMIN);

        $this->assertSame(1, $result['matched']);
        $this->assertSame(1, BankStatementLineMatch::whereNull('released_at')->count());
        $again = $this->service()->autoMatch((int) $statement->id, 1, self::ADMIN);
        $this->assertSame(0, $again['matched']);
        $this->assertSame(1, BankStatementLineMatch::whereNull('released_at')->count());
    }

    public function test_auto_match_can_require_reference_when_the_item_has_an_operation_number(): void
    {
        $this->movement('expense', '2026-11-05', 80.00, 'ZZ998877');
        $this->app['config']->set('treasury.reconciliation.require_reference_when_available', true);

        $statement = $this->statement([['2026-11-05', 'CARGO SIN REFERENCIA', -80.00]], 5000.00);
        $result = $this->service()->autoMatch((int) $statement->id, 1, self::ADMIN);
        $this->assertSame(0, $result['matched']);

        $this->app['config']->set('treasury.reconciliation.require_reference_when_available', false);
        $result = $this->service()->autoMatch((int) $statement->id, 1, self::ADMIN);
        $this->assertSame(1, $result['matched']);
    }

    public function test_foreign_currency_payments_are_listed_as_converted_and_never_auto_matched(): void
    {
        $this->documentPayment(100.00, '2026-11-05', null, 1, 'USD', 3.75); // 375.00 PEN in a PEN account
        $statement = $this->statement([['2026-11-05', 'ABONO', 375.00]], 5000.00);

        $result = $this->service()->autoMatch((int) $statement->id, 1, self::ADMIN);
        $this->assertSame(0, $result['matched']);

        $candidates = $this->service()->listCandidates((int) $statement->id, ['line_id' => $this->lineId($statement, 1)], self::ADMIN);
        $this->assertCount(1, $candidates['rows']);
        $this->assertTrue($candidates['rows'][0]['converted']);
        $this->assertSame(375.00, $candidates['rows'][0]['amount']);
        $this->assertTrue($candidates['rows'][0]['suggested']);
    }

    // ---------------------------------------------------------------------------------------------------------
    // Manual matching
    // ---------------------------------------------------------------------------------------------------------

    public function test_manual_one_to_many_requires_the_exact_signed_sum(): void
    {
        $a = $this->movement('expense', '2026-11-10', 50.00);
        $b = $this->movement('expense', '2026-11-11', 70.00);

        $statement = $this->statement([['2026-11-11', 'CARGO AGRUPADO', -120.00]], 5000.00);
        $lineId = $this->lineId($statement, 1);

        $this->assertValidationMessage(function () use ($lineId, $a) {
            $this->service()->matchLine($lineId, [['type' => 'movement', 'id' => (int) $a->id]], 1, self::ADMIN);
        }, 'no coincide con el importe');

        $line = $this->service()->matchLine($lineId, [
            ['type' => 'movement', 'id' => (int) $a->id],
            ['type' => 'movement', 'id' => (int) $b->id],
        ], 1, self::ADMIN);

        $this->assertSame('matched', $line->status);
        $this->assertSame(2, BankStatementLineMatch::where('bank_statement_line_id', $lineId)->whereNull('released_at')->count());
        $this->assertSame($lineId, (int) TreasuryMovement::find($a->id)->bank_statement_line_id);
        $this->assertSame($lineId, (int) TreasuryMovement::find($b->id)->bank_statement_line_id);
    }

    public function test_manual_match_signed_sum_with_mixed_directions(): void
    {
        $a = $this->movement('expense', '2026-11-10', 120.00);
        $c = $this->movement('income', '2026-11-11', 20.00);
        $statement = $this->statement([['2026-11-11', 'NETO', -100.00]], 5000.00);

        $line = $this->service()->matchLine($this->lineId($statement, 1), [
            ['type' => 'movement', 'id' => (int) $a->id],
            ['type' => 'movement', 'id' => (int) $c->id],
        ], 1, self::ADMIN);

        $this->assertSame('matched', $line->status);
    }

    public function test_manual_match_rejects_other_accounts_voided_and_already_matched_items(): void
    {
        $other = $this->movement('expense', '2026-11-10', 50.00, null, 2);
        $voided = $this->movement('expense', '2026-11-10', 50.00);
        $this->makeMovementService()->void((int) $voided->id, 'anulado para la prueba', 1);
        $good = $this->movement('expense', '2026-11-10', 50.00);

        $statement = $this->statement([['2026-11-10', 'A', -50.00], ['2026-11-10', 'B', -50.00]], 5000.00);

        foreach ([$other, $voided] as $bad) {
            $this->assertValidationMessage(function () use ($statement, $bad) {
                $this->service()->matchLine($this->lineId($statement, 1), [['type' => 'movement', 'id' => (int) $bad->id]], 1, self::ADMIN);
            }, 'no existe, está anulado');
        }

        $this->service()->matchLine($this->lineId($statement, 1), [['type' => 'movement', 'id' => (int) $good->id]], 1, self::ADMIN);

        $this->assertValidationMessage(function () use ($statement, $good) {
            $this->service()->matchLine($this->lineId($statement, 2), [['type' => 'movement', 'id' => (int) $good->id]], 1, self::ADMIN);
        }, 'ya está emparejado');

        $spare = $this->movement('expense', '2026-11-10', 25.00);
        $this->assertValidationMessage(function () use ($statement, $spare) {
            $this->service()->matchLine($this->lineId($statement, 2), [
                ['type' => 'movement', 'id' => (int) $spare->id],
                ['type' => 'movement', 'id' => (int) $spare->id],
            ], 1, self::ADMIN);
        }, 'dos veces');

        // The same global payment cannot back two lines either.
        $gp = $this->documentPayment(50.00, '2026-11-10');
        $second = $this->statement([['2026-11-22', 'X', 50.00], ['2026-11-22', 'Y', 50.00]], 5000.00, '2026-11-21', '2026-11-30');
        $this->service()->matchLine($this->lineId($second, 1), [['type' => 'global_payment', 'id' => $gp]], 1, self::ADMIN);
        $this->assertValidationMessage(function () use ($second, $gp) {
            $this->service()->matchLine($this->lineId($second, 2), [['type' => 'global_payment', 'id' => $gp]], 1, self::ADMIN);
        }, 'no existe, está anulado');
    }

    public function test_unmatch_releases_the_match_and_allows_matching_again(): void
    {
        $movement = $this->movement('expense', '2026-11-10', 50.00);
        $statement = $this->statement([['2026-11-10', 'A', -50.00]], 5000.00);
        $lineId = $this->lineId($statement, 1);

        $this->service()->matchLine($lineId, [['type' => 'movement', 'id' => (int) $movement->id]], 1, self::ADMIN);
        $line = $this->service()->unmatchLine($lineId, 1, self::ADMIN);

        $this->assertSame('unmatched', $line->status);
        $this->assertNull(TreasuryMovement::find($movement->id)->bank_statement_line_id);
        $released = BankStatementLineMatch::where('bank_statement_line_id', $lineId)->first();
        $this->assertNotNull($released->released_at);
        $this->assertNull($released->active_match_key);

        $this->service()->matchLine($lineId, [['type' => 'movement', 'id' => (int) $movement->id]], 1, self::ADMIN);
        $this->assertSame(1, BankStatementLineMatch::whereNull('released_at')->count());
        $this->assertSame(2, BankStatementLineMatch::count()); // history preserved
    }

    public function test_unique_key_prevents_two_active_matches_for_the_same_item(): void
    {
        $movement = $this->movement('expense', '2026-11-10', 50.00);
        $statement = $this->statement([['2026-11-10', 'A', -50.00], ['2026-11-10', 'B', -50.00]], 5000.00);
        $key = BankStatementLineMatch::keyFor(TreasuryMovement::class, (int) $movement->id);

        $row = function (int $lineNo) use ($statement, $movement, $key) {
            return [
                'bank_statement_line_id' => $this->lineId($statement, $lineNo),
                'matchable_type' => TreasuryMovement::class,
                'matchable_id' => $movement->id,
                'amount' => -50,
                'item_date' => '2026-11-10',
                'active_match_key' => $key,
            ];
        };

        $this->db->table('bank_statement_line_matches')->insert($row(1));

        try {
            $this->db->table('bank_statement_line_matches')->insert($row(2));
            $this->fail('The unique key must reject a second active match.');
        } catch (QueryException $e) {
            $this->assertTrue(\Modules\Treasury\Support\DatabaseErrors::isDuplicateKey($e));
        }

        // Released rows (NULL key) never conflict.
        $released = $row(2);
        $released['active_match_key'] = null;
        $this->db->table('bank_statement_line_matches')->insert($released);
        $this->assertSame(2, BankStatementLineMatch::count());
    }

    public function test_ignore_requires_reason_and_can_be_undone(): void
    {
        $statement = $this->statement([['2026-11-10', 'A', -50.00]], 5000.00);
        $lineId = $this->lineId($statement, 1);

        $this->assertValidationMessage(function () use ($lineId) {
            $this->service()->ignoreLine($lineId, ' ', 1, self::ADMIN);
        }, 'motivo');

        $line = $this->service()->ignoreLine($lineId, 'Cargo de otra empresa', 1, self::ADMIN);
        $this->assertSame('ignored', $line->status);
        $this->assertSame('Cargo de otra empresa', $line->ignore_reason);

        $this->assertValidationMessage(function () use ($lineId) {
            $this->service()->matchLine($lineId, [['type' => 'movement', 'id' => 1]], 1, self::ADMIN);
        }, 'ya está emparejada o ignorada');

        $line = $this->service()->unignoreLine($lineId, 1, self::ADMIN);
        $this->assertSame('unmatched', $line->status);
        $this->assertNull($line->ignore_reason);
    }

    // ---------------------------------------------------------------------------------------------------------
    // Create from line
    // ---------------------------------------------------------------------------------------------------------

    public function test_create_movement_from_line_uses_movement_service_and_auto_matches(): void
    {
        $statement = $this->statement([
            ['2026-11-15', 'COMISION MANTENIMIENTO', -15.00, 'COM9911'],
            ['2026-11-15', 'ITF', -0.50],
            ['2026-11-16', 'INTERESES AHORRO', 2.50],
            ['2026-11-17', 'AJUSTE', 8.00],
        ], 5000.00);

        $service = $this->service();
        $fee = $service->createMovementFromLine($this->lineId($statement, 1), ['kind' => 'bank_fee', 'establishment_id' => 1], 1, self::ADMIN);
        $itf = $service->createMovementFromLine($this->lineId($statement, 2), ['kind' => 'itf', 'establishment_id' => 1], 1, self::ADMIN);
        $interest = $service->createMovementFromLine($this->lineId($statement, 3), ['kind' => 'interest', 'establishment_id' => 1], 1, self::ADMIN);
        $adjustment = $service->createMovementFromLine($this->lineId($statement, 4), ['kind' => 'adjustment', 'description' => 'Ajuste del banco'], 1, self::ADMIN);

        $this->assertSame('bank_fee', $fee['movement']->kind);
        $this->assertSame(-1, (int) $fee['movement']->direction);
        $this->assertSame(15.00, $fee['movement']->amount);
        $this->assertSame('COM9911', $fee['movement']->operation_number);
        $this->assertSame(2, (int) $fee['movement']->treasury_concept_id); // default concept: Comisión bancaria
        $this->assertSame('itf', $itf['movement']->kind);
        $this->assertSame('interest', $interest['movement']->kind);
        $this->assertSame(1, (int) $interest['movement']->direction);
        $this->assertSame('adjustment', $adjustment['movement']->kind);
        $this->assertSame(1, (int) $adjustment['movement']->direction);
        $this->assertSame('Ajuste del banco', $adjustment['movement']->description);

        foreach ([1, 2, 3, 4] as $lineNo) {
            $this->assertSame('matched', $this->line($statement, $lineNo)->status);
        }
        $this->assertSame($this->lineId($statement, 1), (int) $fee['movement']->bank_statement_line_id);
        $this->assertSame(4, BankStatementLineMatch::whereNull('released_at')->count());
        $this->assertSame(4, TreasuryMovement::count());
    }

    public function test_create_movement_from_line_validates_sign_kind_and_concept(): void
    {
        $statement = $this->statement([['2026-11-15', 'CARGO', -15.00], ['2026-11-15', 'ABONO', 40.00]], 5000.00);
        $service = $this->service();

        $this->assertValidationMessage(function () use ($service, $statement) {
            $service->createMovementFromLine($this->lineId($statement, 2), ['kind' => 'bank_fee'], 1, self::ADMIN);
        }, 'corresponde a un cargo');

        $this->assertValidationMessage(function () use ($service, $statement) {
            $service->createMovementFromLine($this->lineId($statement, 1), ['kind' => 'interest'], 1, self::ADMIN);
        }, 'corresponde a un abono');

        $this->assertValidationMessage(function () use ($service, $statement) {
            $service->createMovementFromLine($this->lineId($statement, 1), ['kind' => 'tax_payment'], 1, self::ADMIN);
        }, 'no es válido');

        // income/expense need an explicit concept of the right type.
        $this->assertValidationMessage(function () use ($service, $statement) {
            $service->createMovementFromLine($this->lineId($statement, 2), ['kind' => 'income'], 1, self::ADMIN);
        }, 'Seleccione el concepto');

        $this->assertValidationMessage(function () use ($service, $statement) {
            $service->createMovementFromLine($this->lineId($statement, 2), ['kind' => 'income', 'treasury_concept_id' => 5], 1, self::ADMIN);
        }, 'concepto de tipo ingreso');

        $created = $service->createMovementFromLine($this->lineId($statement, 2), ['kind' => 'income', 'treasury_concept_id' => 4], 1, self::ADMIN);
        $this->assertSame('income', $created['movement']->kind);
        $this->assertSame(0, TreasuryMovement::where('kind', 'bank_fee')->count());
    }

    public function test_create_movement_from_line_respects_the_detraction_account_guard(): void
    {
        $bn = $this->statement([['2026-11-15', 'COMISION', -5.00]], 0.00, '2026-11-01', '2026-11-20', 4);

        $this->assertValidationMessage(function () use ($bn) {
            $this->service()->createMovementFromLine($this->lineId($bn, 1), ['kind' => 'bank_fee'], 1, self::ADMIN);
        }, 'solo puede usarse para pagar tributos');

        // The failed attempt left nothing behind.
        $this->assertSame(0, TreasuryMovement::count());
        $this->assertSame('unmatched', $this->line($bn, 1)->status);
    }

    // ---------------------------------------------------------------------------------------------------------
    // Closing
    // ---------------------------------------------------------------------------------------------------------

    /**
     * Builds the acceptance scenario: every line matched or explained, difference 0.
     */
    private function acceptanceScenario(): array
    {
        $income = $this->movement('income', '2026-11-03', 1000.00, 'OP123456');
        $gp = $this->documentPayment(300.00, '2026-11-05', 'REF-9988');
        $purchaseGp = $this->purchasePayment(200.00, '2026-11-07');
        $e1 = $this->movement('expense', '2026-11-10', 50.00);
        $e2 = $this->movement('expense', '2026-11-11', 70.00);
        $inTransit = $this->movement('expense', '2026-11-19', 80.00, null, 1, 'Cheque en tránsito');

        // closing = 5000 + 1000 + 300 - 200 - 120 - 15 + 2.50 - 30 = 5937.50
        $statement = $this->statement([
            ['2026-11-04', 'ABONO TRANSFERENCIA', 1000.00, 'OP123456'],
            ['2026-11-06', 'TRANSF REF9988 CLIENTE', 300.00],
            ['2026-11-07', 'PAGO PROVEEDOR', -200.00],
            ['2026-11-11', 'CARGOS VARIOS', -120.00],
            ['2026-11-15', 'COMISION MANTENIMIENTO', -15.00],
            ['2026-11-18', 'CARGO NO RECONOCIDO', -30.00],
            ['2026-11-20', 'INTERESES', 2.50],
        ], 5000.00);

        $service = $this->service();
        $auto = $service->autoMatch((int) $statement->id, 1, self::ADMIN);
        $service->matchLine($this->lineId($statement, 4), [
            ['type' => 'movement', 'id' => (int) $e1->id],
            ['type' => 'movement', 'id' => (int) $e2->id],
        ], 1, self::ADMIN);
        $service->createMovementFromLine($this->lineId($statement, 5), ['kind' => 'bank_fee', 'establishment_id' => 1], 1, self::ADMIN);
        $service->ignoreLine($this->lineId($statement, 6), 'Cargo de otra cuenta, reclamado al banco', 1, self::ADMIN);
        $service->createMovementFromLine($this->lineId($statement, 7), ['kind' => 'interest', 'establishment_id' => 1], 1, self::ADMIN);
        $this->normalizeDates();

        return [$statement, compact('income', 'e1', 'e2', 'inTransit', 'gp', 'purchaseGp', 'auto')];
    }

    public function test_close_succeeds_when_everything_is_matched_or_explained_and_difference_is_zero(): void
    {
        [$statement, $items] = $this->acceptanceScenario();
        $this->assertSame(3, $items['auto']['matched']);

        $live = $this->service()->summary((int) $statement->id, self::ADMIN);
        $this->assertTrue($live['balanced']);
        $this->assertEquals(0.0, $live['difference']);

        $closed = $this->service()->closeStatement((int) $statement->id, 1, self::ADMIN);

        $this->assertSame('closed', $closed->status);
        $snapshot = $closed->snapshot;
        $this->assertEquals(5937.50, $snapshot['bank_closing_balance']);
        $this->assertEquals(5887.50, $snapshot['system_balance']);
        $this->assertSame('2026-11-20', $snapshot['system_balance_as_of']);
        $this->assertEquals(-30.0, $snapshot['ignored']['net']);
        $this->assertSame(1, $snapshot['in_transit']['count']);
        $this->assertEquals(-80.0, $snapshot['in_transit']['net']);
        $this->assertSame('Cheque en tránsito', $snapshot['in_transit']['items'][0]['description']);
        $this->assertEquals(5887.50, $snapshot['adjusted_bank_balance']);
        $this->assertEquals(0.0, $snapshot['difference']);
        $this->assertTrue($snapshot['balanced']);
        $this->assertSame(6, $snapshot['matched']['count']);

        // Movements matched to the statement are flagged as reconciled; the in-transit one is not.
        $this->assertNotNull(TreasuryMovement::find($items['income']->id)->reconciled_at);
        $this->assertNotNull(TreasuryMovement::find($items['e1']->id)->reconciled_at);
        $this->assertNull(TreasuryMovement::find($items['inTransit']->id)->reconciled_at);
        $this->assertSame(5, TreasuryMovement::whereNotNull('reconciled_at')->count()); // income, e1, e2, fee, interest

        // A closed statement is immutable until reopened.
        $this->assertValidationMessage(function () use ($statement) {
            $this->service()->unmatchLine($this->lineId($statement, 1), 1, self::ADMIN);
        }, 'está cerrada');
        $this->assertValidationMessage(function () use ($statement) {
            $this->service()->autoMatch((int) $statement->id, 1, self::ADMIN);
        }, 'está cerrada');
        $this->assertValidationMessage(function () use ($statement) {
            $this->service()->discardStatement((int) $statement->id, 1, self::ADMIN);
        }, 'está cerrada');

        // The stored snapshot is what summary() returns for a closed statement.
        $this->assertSame($snapshot['difference'], $this->service()->summary((int) $statement->id, self::ADMIN)['difference']);
    }

    public function test_close_is_refused_with_unmatched_lines(): void
    {
        $statement = $this->statement([['2026-11-10', 'A', -50.00], ['2026-11-11', 'B', -20.00]], 5000.00);
        $this->service()->ignoreLine($this->lineId($statement, 1), 'No es nuestro', 1, self::ADMIN);

        $this->assertValidationMessage(function () use ($statement) {
            $this->service()->closeStatement((int) $statement->id, 1, self::ADMIN);
        }, 'quedan 1 línea(s) sin emparejar');

        $this->assertSame('draft', BankStatement::find($statement->id)->status);
    }

    public function test_close_is_refused_when_the_difference_is_not_zero(): void
    {
        $movement = $this->movement('expense', '2026-11-10', 50.00);
        // Opening balance 100.00 above the books: everything matches but the cumulative difference is +100.
        $statement = $this->statement([['2026-11-10', 'CARGO', -50.00]], 5100.00);
        $this->service()->matchLine($this->lineId($statement, 1), [['type' => 'movement', 'id' => (int) $movement->id]], 1, self::ADMIN);

        $this->assertValidationMessage(function () use ($statement) {
            $this->service()->closeStatement((int) $statement->id, 1, self::ADMIN);
        }, 'la diferencia es 100.00');

        $reloaded = BankStatement::find($statement->id);
        $this->assertSame('draft', $reloaded->status);
        $this->assertNull(TreasuryMovement::find($movement->id)->reconciled_at);
    }

    public function test_items_matched_after_the_period_end_do_not_break_the_difference(): void
    {
        // The bank posted on 10-31 a charge the books dated 11-02: it is "ahead" of the period.
        $movement = $this->movement('expense', '2026-11-02', 40.00);
        $statement = $this->statement([['2026-10-31', 'CARGO ADELANTADO', -40.00]], 5000.00, '2026-10-01', '2026-10-31');

        $auto = $this->service()->autoMatch((int) $statement->id, 1, self::ADMIN);
        $this->assertSame(1, $auto['matched']);

        $summary = $this->service()->summary((int) $statement->id, self::ADMIN);
        $this->assertEquals(-40.0, $summary['ahead_net']);
        $this->assertSame(0, $summary['in_transit']['count']);
        $this->assertTrue($summary['balanced']);

        $this->service()->closeStatement((int) $statement->id, 1, self::ADMIN);
        $this->assertNotNull(TreasuryMovement::find($movement->id)->reconciled_at);
    }

    public function test_second_statement_is_cumulative_and_keeps_earlier_ignored_lines(): void
    {
        [$first] = $this->acceptanceScenario();
        $this->service()->closeStatement((int) $first->id, 1, self::ADMIN);

        // The cheque in transit (-80, dated 11-19) clears on 11-21.
        $second = $this->statement([['2026-11-21', 'CHEQUE COBRADO', -80.00]], 5937.50, '2026-11-21', '2026-11-22');
        $this->normalizeDates();
        $result = $this->service()->autoMatch((int) $second->id, 1, self::ADMIN);
        $this->assertSame(1, $result['matched']);

        $summary = $this->service()->summary((int) $second->id, self::ADMIN);
        $this->assertSame(0, $summary['in_transit']['count']);
        $this->assertEquals(-30.0, $summary['ignored']['net_cumulative']);
        $this->assertTrue($summary['balanced'], 'difference ' . $summary['difference']);

        $closed = $this->service()->closeStatement((int) $second->id, 1, self::ADMIN);
        $this->assertSame('closed', $closed->status);
    }

    public function test_reopen_is_admin_only_clears_reconciled_flags_and_requires_reverse_order(): void
    {
        [$first, $items] = $this->acceptanceScenario();
        $this->service()->closeStatement((int) $first->id, 1, self::ADMIN);
        $second = $this->statement([['2026-11-21', 'CHEQUE COBRADO', -80.00]], 5937.50, '2026-11-21', '2026-11-22');
        $this->service()->autoMatch((int) $second->id, 1, self::ADMIN);
        $this->service()->closeStatement((int) $second->id, 1, self::ADMIN);

        try {
            $this->service()->reopenStatement((int) $second->id, 2, $this->user(1));
            $this->fail('A non-admin must not reopen a reconciliation.');
        } catch (AuthorizationException $e) {
            $this->assertStringContainsString('administrador', $e->getMessage());
        }
        $this->assertSame('closed', BankStatement::find($second->id)->status);

        // Earlier statement cannot be reopened while a later one is closed.
        $this->assertValidationMessage(function () use ($first) {
            $this->service()->reopenStatement((int) $first->id, 1, self::ADMIN);
        }, 'posterior cerrada');

        $this->service()->reopenStatement((int) $second->id, 1, self::ADMIN);
        $reopened = $this->service()->reopenStatement((int) $first->id, 1, self::ADMIN);

        $this->assertSame('draft', $reopened->status);
        $this->assertNull($reopened->snapshot);
        $this->assertNull($reopened->closed_at);
        $this->assertSame(0, TreasuryMovement::whereNotNull('reconciled_at')->count());

        // And it can be closed again.
        $this->service()->closeStatement((int) $first->id, 1, self::ADMIN);
        $this->assertSame('closed', BankStatement::find($first->id)->status);
    }

    public function test_void_is_blocked_while_reconciled_or_matched_and_allowed_after_reopen_and_unmatch(): void
    {
        [$statement, $items] = $this->acceptanceScenario();
        $movements = $this->makeMovementService();

        // Matched in a draft statement: unmatch first.
        $this->assertValidationMessage(function () use ($movements, $items) {
            $movements->void((int) $items['income']->id, 'intento de anulación', 1);
        }, 'emparejado con un extracto bancario');

        $this->service()->closeStatement((int) $statement->id, 1, self::ADMIN);

        // Reconciled (closed statement).
        $this->assertValidationMessage(function () use ($movements, $items) {
            $movements->void((int) $items['income']->id, 'intento de anulación', 1);
        }, 'conciliación bancaria cerrada');

        // The in-transit item is not part of the reconciliation and stays voidable.
        $voidResult = $movements->void((int) $items['inTransit']->id, 'cheque anulado', 1);
        $this->assertSame(1, $voidResult['count']);

        // Reopen + unmatch frees the movement.
        $this->service()->reopenStatement((int) $statement->id, 1, self::ADMIN);
        $lineId = (int) TreasuryMovement::find($items['income']->id)->bank_statement_line_id;
        $this->service()->unmatchLine($lineId, 1, self::ADMIN);
        $voided = $movements->void((int) $items['income']->id, 'ahora si se puede', 1);
        $this->assertSame(1, $voided['count']);
    }

    public function test_close_is_refused_when_a_matched_payment_disappeared(): void
    {
        $gp = $this->documentPayment(300.00, '2026-11-05');
        $statement = $this->statement([['2026-11-06', 'ABONO', 300.00]], 5000.00);
        $this->service()->autoMatch((int) $statement->id, 1, self::ADMIN);

        // The payment's document is voided after matching (state 11).
        $this->db->table('documents')->update(['state_type_id' => '11']);

        $this->assertValidationMessage(function () use ($statement) {
            $this->service()->closeStatement((int) $statement->id, 1, self::ADMIN);
        }, 'ya no existe o fue anulado');
    }

    // ---------------------------------------------------------------------------------------------------------
    // Access
    // ---------------------------------------------------------------------------------------------------------

    public function test_non_admin_is_confined_to_their_establishment_accounts(): void
    {
        $this->db->table('bank_accounts')->insert([
            'id' => 5, 'bank_id' => 1, 'description' => 'Sucursal', 'number' => '191-005', 'currency_type_id' => 'PEN', 'status' => 1,
            'initial_balance' => 0, 'show_in_documents' => 1, 'account_type' => 'checking', 'itf_exempt' => 0, 'establishment_id' => 2,
        ]);
        $this->db->table('bank_accounts')->insert([
            'id' => 6, 'bank_id' => 1, 'description' => 'Compartida', 'number' => '191-006', 'currency_type_id' => 'PEN', 'status' => 1,
            'initial_balance' => 0, 'show_in_documents' => 1, 'account_type' => 'checking', 'itf_exempt' => 0, 'establishment_id' => null,
        ]);

        $own = $this->statement([['2026-11-05', 'A', 10.00]], 5000.00, '2026-11-01', '2026-11-20', 1, $this->user(1));
        $foreign = $this->statement([['2026-11-05', 'A', 10.00]], 0.00, '2026-11-01', '2026-11-20', 5, self::ADMIN);
        $shared = $this->statement([['2026-11-05', 'A', 10.00]], 0.00, '2026-11-01', '2026-11-20', 6, $this->user(1));
        $this->assertNotNull($shared->id);

        $denied = [
            function () { $this->statement([['2026-12-05', 'A', 10.00]], 0.00, '2026-12-01', '2026-12-20', 5, $this->user(1)); },
            function () use ($foreign) { $this->service()->getStatement((int) $foreign->id, $this->user(1)); },
            function () use ($foreign) { $this->service()->listLines((int) $foreign->id, [], $this->user(1)); },
            function () use ($foreign) { $this->service()->listCandidates((int) $foreign->id, [], $this->user(1)); },
            function () use ($foreign) { $this->service()->summary((int) $foreign->id, $this->user(1)); },
            function () use ($foreign) { $this->service()->autoMatch((int) $foreign->id, 2, $this->user(1)); },
            function () use ($foreign) { $this->service()->closeStatement((int) $foreign->id, 2, $this->user(1)); },
            function () use ($foreign) { $this->service()->discardStatement((int) $foreign->id, 2, $this->user(1)); },
            function () use ($foreign) { $this->service()->ignoreLine($this->lineId($foreign, 1), 'motivo', 2, $this->user(1)); },
            function () use ($foreign) { $this->service()->createMovementFromLine($this->lineId($foreign, 1), ['kind' => 'interest', 'establishment_id' => 2], 2, $this->user(1)); },
            function () use ($foreign) { $this->service()->matchLine($this->lineId($foreign, 1), [['type' => 'movement', 'id' => 1]], 2, $this->user(1)); },
        ];

        foreach ($denied as $i => $call) {
            try {
                $call();
                $this->fail("Operation {$i} must be denied for a non-admin of another establishment.");
            } catch (AuthorizationException $e) {
                $this->assertStringContainsString('otra sede', $e->getMessage());
            }
        }

        // Own-establishment and shared accounts work; the list only shows reachable statements.
        $this->assertSame($own->id, $this->service()->getStatement((int) $own->id, $this->user(1))['id']);
        $ids = array_column($this->service()->listStatements([], $this->user(1))['rows'], 'id');
        $this->assertEqualsCanonicalizing([(int) $own->id, (int) $shared->id], $ids);
        $this->assertCount(3, $this->service()->listStatements([], self::ADMIN)['rows']);
    }

    public function test_non_admin_without_establishment_is_denied_everywhere(): void
    {
        $statement = $this->statement([['2026-11-05', 'A', 10.00]], 5000.00);
        $nobody = ['is_admin' => false, 'user_id' => 2, 'establishment_id' => 0];

        foreach ([
            function () use ($nobody) { $this->statement([['2026-12-05', 'A', 10.00]], 0.00, '2026-12-01', '2026-12-20', 1, $nobody); },
            function () use ($statement, $nobody) { $this->service()->getStatement((int) $statement->id, $nobody); },
            function () use ($statement, $nobody) { $this->service()->autoMatch((int) $statement->id, 2, $nobody); },
            function () use ($nobody) { $this->service()->saveMapping(['name' => 'x'], [], 2, $nobody); },
        ] as $call) {
            try {
                $call();
                $this->fail('A non-admin without establishment must be denied.');
            } catch (AuthorizationException $e) {
                $this->assertTrue(true);
            }
        }

        $this->assertSame([], $this->service()->listStatements([], $nobody)['rows']);
        $this->assertSame(0, $this->service()->listStatements([], $nobody)['meta']['total']);
    }

    // ---------------------------------------------------------------------------------------------------------
    // Presentation and mappings
    // ---------------------------------------------------------------------------------------------------------

    public function test_list_lines_includes_matched_items_with_labels(): void
    {
        $movement = $this->movement('expense', '2026-11-10', 50.00, null, 1, 'Pago de luz');
        $gp = $this->documentPayment(300.00, '2026-11-05', 'REF-1');
        $statement = $this->statement([['2026-11-10', 'LUZ', -50.00], ['2026-11-06', 'ABONO', 300.00]], 5000.00);
        $this->service()->autoMatch((int) $statement->id, 1, self::ADMIN);

        $lines = $this->service()->listLines((int) $statement->id, [], self::ADMIN);
        $this->assertSame(2, $lines['meta']['total']);
        $this->assertSame('Egreso manual', $lines['rows'][0]['items'][0]['label']);
        $this->assertSame('Pago de luz', $lines['rows'][0]['items'][0]['description']);
        $this->assertStringContainsString('Cobro CPE', $lines['rows'][1]['items'][0]['description']);

        $filtered = $this->service()->listLines((int) $statement->id, ['status' => 'unmatched'], self::ADMIN);
        $this->assertSame(0, $filtered['meta']['total']);

        $detail = $this->service()->getStatement((int) $statement->id, self::ADMIN);
        $this->assertSame(['total' => 2, 'matched' => 2, 'ignored' => 0, 'unmatched' => 0], $detail['lines']);
    }

    public function test_mappings_are_saved_listed_and_protected(): void
    {
        $config = ['header_row' => 1, 'date_column' => 1, 'description_column' => 2, 'amount_mode' => 'signed', 'amount_column' => 3, 'date_format' => 'd/m/Y'];
        $service = $this->service();

        $mapping = $service->saveMapping(['name' => 'BCP soles', 'bank_account_id' => 1], $config, 2, $this->user(1));
        $global = $service->saveMapping(['name' => 'Genérico'], $config, 1, self::ADMIN);
        $this->assertSame(1, (int) $mapping->bank_id);

        $names = array_column($service->listMappings($this->user(1)), 'name');
        $this->assertEqualsCanonicalizing(['BCP soles', 'Genérico'], $names);
        $this->assertSame(['BCP soles', 'Genérico'], array_column($service->listMappings(self::ADMIN, 1), 'name'));

        $this->assertValidationMessage(function () use ($service, $config) {
            $service->saveMapping(['name' => ''], $config, 1, self::ADMIN);
        }, 'nombre del mapeo');

        // Another non-admin cannot edit/delete a mapping they did not create.
        $stranger = ['is_admin' => false, 'user_id' => 9, 'establishment_id' => 1];
        try {
            $service->deleteMapping((int) $mapping->id, 9, $stranger);
            $this->fail('Only the creator or an admin may delete a mapping.');
        } catch (AuthorizationException $e) {
            $this->assertTrue(true);
        }

        $service->deleteMapping((int) $mapping->id, 2, $this->user(1));
        $this->assertCount(1, $service->listMappings(self::ADMIN));
    }

    public function test_migrations_are_idempotent_and_down_only_drops_their_own_tables(): void
    {
        $statements = new \TenantCreateBankStatementsTables();
        $matches = new \TenantCreateBankStatementLineMatchesTable();
        $schema = $this->db->getSchemaBuilder();

        // Running up() again is a no-op.
        $statements->up();
        $matches->up();
        foreach (['bank_statements', 'bank_statement_lines', 'bank_statement_mappings', 'bank_statement_line_matches'] as $table) {
            $this->assertTrue($schema->hasTable($table), $table);
        }

        $matches->down();
        $this->assertFalse($schema->hasTable('bank_statement_line_matches'));
        $this->assertTrue($schema->hasTable('bank_statements'));

        $statements->down();
        foreach (['bank_statements', 'bank_statement_lines', 'bank_statement_mappings'] as $table) {
            $this->assertFalse($schema->hasTable($table), $table);
        }
        // Tables owned by earlier phases are untouched; the reconciliation columns of Phase 1 stay.
        $this->assertTrue($schema->hasTable('treasury_movements'));
        $this->assertTrue($schema->hasColumn('treasury_movements', 'bank_statement_line_id'));
        $this->assertTrue($schema->hasColumn('treasury_movements', 'reconciled_at'));
        $this->assertFalse($schema->hasColumn('global_payments', 'reconciled_at'));

        $statements->up();
        $matches->up();
        $this->assertTrue($schema->hasTable('bank_statement_line_matches'));
    }

    // ---------------------------------------------------------------------------------------------------------
    // Judgment-day hardening (C1, W3-W8, S1)
    // ---------------------------------------------------------------------------------------------------------

    private function creditNote(int $documentId, float $total, string $issuedOn, string $currency = 'PEN', float $rate = 1.0): int
    {
        static $seq = 0;
        $seq++;
        $noteDocumentId = $this->insertDocument([
            'document_type_id' => '07', 'series' => 'FC01', 'number' => (string) $seq, 'date_of_issue' => $issuedOn,
            'total' => $total, 'currency_type_id' => $currency, 'exchange_rate_sale' => $rate,
        ]);
        $this->db->table('notes')->insert(['document_id' => $noteDocumentId, 'note_type' => 'credit', 'affected_document_id' => $documentId]);

        return $noteDocumentId;
    }

    private function documentOfPayment(int $globalPaymentId): int
    {
        return (int) $this->db->table('global_payments as gp')
            ->join('document_payments as p', 'p.id', '=', 'gp.payment_id')
            ->where('gp.id', $globalPaymentId)
            ->value('p.document_id');
    }

    public function test_credit_note_deduction_is_an_explained_book_adjustment_and_close_succeeds(): void
    {
        $gp = $this->documentPayment(1000.00, '2026-11-05');
        $this->creditNote($this->documentOfPayment($gp), 1000.00, '2026-11-06');

        // Books: 5000 + 1000 (payment) - 1000 (credit note) = 5000. The bank only shows the +1,000.
        $this->assertEquals(5000.0, $this->makeBalanceService()->getBankAccountBalanceAsOf(1, '2026-11-20')['balance']);

        $statement = $this->statement([['2026-11-05', 'ABONO CLIENTE', 1000.00]], 5000.00);
        $this->assertSame(1, $this->service()->autoMatch((int) $statement->id, 1, self::ADMIN)['matched']);

        $summary = $this->service()->summary((int) $statement->id, self::ADMIN);
        $this->assertSame(1, $summary['book_adjustments']['count']);
        $this->assertEquals(-1000.0, $summary['book_adjustments']['net']);
        $this->assertSame('credit_note_adjustment', $summary['book_adjustments']['items'][0]['type']);
        $this->assertFalse($summary['book_adjustments']['items'][0]['matchable']);
        $this->assertStringContainsString('FC01-', $summary['book_adjustments']['items'][0]['description']);
        $this->assertEquals(0.0, $summary['difference']);
        $this->assertTrue($summary['balanced']);

        $closed = $this->service()->closeStatement((int) $statement->id, 1, self::ADMIN);
        $this->assertSame('closed', $closed->status);
        $this->assertEquals(-1000.0, $closed->snapshot['book_adjustments']['net']);
        $this->assertSame(1, $closed->snapshot['book_adjustments']['count']);
        $this->assertEquals(0.0, $closed->snapshot['difference']);

        // Adjustments are never candidates for matching.
        $candidates = $this->service()->listCandidates((int) $statement->id, [], self::ADMIN)['rows'];
        $this->assertSame([], array_values(array_filter($candidates, function ($c) {
            return $c['type'] === 'credit_note_adjustment';
        })));
    }

    public function test_credit_note_issued_after_the_period_is_excluded_from_the_as_of_balance(): void
    {
        $gp = $this->documentPayment(1000.00, '2026-11-05');
        $documentId = $this->documentOfPayment($gp);
        $this->creditNote($documentId, 1000.00, '2026-12-05'); // after period_to

        $balance = $this->makeBalanceService();
        $this->assertEquals(6000.0, $balance->getBankAccountBalanceAsOf(1, '2026-11-20')['balance']);
        $this->assertEquals(5000.0, $balance->getBankAccountBalanceAsOf(1, '2026-12-10')['balance']);
        $this->assertSame([], $balance->getBankAccountCreditNoteAdjustments(1, '2026-11-20'));
        $this->assertCount(1, $balance->getBankAccountCreditNoteAdjustments(1, '2026-12-10'));

        $statement = $this->statement([['2026-11-05', 'ABONO CLIENTE', 1000.00]], 5000.00);
        $this->service()->autoMatch((int) $statement->id, 1, self::ADMIN);
        $summary = $this->service()->summary((int) $statement->id, self::ADMIN);

        $this->assertSame(0, $summary['book_adjustments']['count']);
        $this->assertTrue($summary['balanced'], 'difference ' . $summary['difference']);
    }

    public function test_current_balance_keeps_deducting_credit_notes_regardless_of_their_date(): void
    {
        $gp = $this->documentPayment(1000.00, '2026-11-05');
        $this->creditNote($this->documentOfPayment($gp), 1000.00, '2026-12-05');

        // The current-balance behaviour (no as-of date) is unchanged: the credit note is deducted regardless of date.
        $this->assertEquals(5000.0, $this->makeBalanceService()->getBankAccountBalanceAsOf(1, null)['balance']);
    }

    public function test_cross_currency_rounding_drift_is_absorbed_by_the_aggregate_in_transit_total(): void
    {
        // Three USD 1.00 payments at 1.3349: each item rounds to 1.33 (3.99) but the balance sums 4.0047.
        $this->documentPayment(1.00, '2026-11-08', null, 1, 'USD', 1.3349);
        $this->documentPayment(1.00, '2026-11-09', null, 1, 'USD', 1.3349);
        $this->documentPayment(1.00, '2026-11-10', null, 1, 'USD', 1.3349);
        $movement = $this->movement('income', '2026-11-03', 10.00, 'OP123456');

        $statement = $this->statement([['2026-11-03', 'ABONO', 10.00, 'OP123456']], 5000.00);
        $this->service()->autoMatch((int) $statement->id, 1, self::ADMIN);

        $summary = $this->service()->summary((int) $statement->id, self::ADMIN);
        $this->assertSame(3, $summary['in_transit']['count']);
        $this->assertEquals(3.99, $summary['in_transit']['items_net']);
        $this->assertEquals(0.01, $summary['in_transit']['rounding_drift']);
        $this->assertEquals(4.00, $summary['in_transit']['net']);
        $this->assertTrue($summary['balanced'], 'difference ' . $summary['difference']);
        $this->assertSame('closed', $this->service()->closeStatement((int) $statement->id, 1, self::ADMIN)->status);
    }

    public function test_aggregate_in_transit_does_not_hide_a_real_opening_balance_gap(): void
    {
        $this->documentPayment(1.00, '2026-11-08', null, 1, 'USD', 1.3349);
        $this->documentPayment(1.00, '2026-11-09', null, 1, 'USD', 1.3349);
        $this->documentPayment(1.00, '2026-11-10', null, 1, 'USD', 1.3349);
        $movement = $this->movement('income', '2026-11-03', 10.00, 'OP123456');

        // Opening balance 100.00 above the books: the rounding drift is absorbed, the real gap is not.
        $statement = $this->statement([['2026-11-03', 'ABONO', 10.00, 'OP123456']], 5100.00);
        $this->service()->autoMatch((int) $statement->id, 1, self::ADMIN);

        $summary = $this->service()->summary((int) $statement->id, self::ADMIN);
        $this->assertEquals(0.01, $summary['in_transit']['rounding_drift']);
        $this->assertEquals(100.0, $summary['difference']);
        $this->assertFalse($summary['balanced']);
    }

    public function test_auto_match_revalidates_each_pick_and_skips_a_candidate_voided_after_loading(): void
    {
        $voidedLater = $this->movement('expense', '2026-11-10', 77.00);
        $kept = $this->movement('expense', '2026-11-11', 33.00);
        $paymentGp = $this->documentPayment(300.00, '2026-11-05');
        $statement = $this->statement([
            ['2026-11-10', 'CARGO A', -77.00],
            ['2026-11-11', 'CARGO B', -33.00],
            ['2026-11-06', 'ABONO', 300.00],
        ], 5000.00);

        $movements = $this->makeMovementService();
        $db = $this->db;
        $service = new class($movements, $this->makeBalanceService(), $voidedLater->id, $db) extends ReconciliationService {
            private $victim;
            private $db;

            public function __construct($movements, $balance, $victim, $db)
            {
                parent::__construct($movements, $balance);
                $this->victim = $victim;
                $this->db = $db;
            }

            protected function afterAutoMatchItemsLoaded(array $items): void
            {
                // Between loading and attaching: one movement gets voided and the payment's document is voided.
                $this->db->table('treasury_movements')->where('id', $this->victim)->update(['state' => 'voided']);
                $this->db->table('documents')->update(['state_type_id' => '11']);
            }
        };

        $result = $service->autoMatch((int) $statement->id, 1, self::ADMIN);

        $this->assertSame(1, $result['matched']);
        $this->assertSame(2, $result['conflicts']);
        $this->assertSame('unmatched', $this->line($statement, 1)->status);
        $this->assertSame('matched', $this->line($statement, 2)->status);
        $this->assertSame('unmatched', $this->line($statement, 3)->status);
        $this->assertSame(1, BankStatementLineMatch::whereNull('released_at')->count());
        $this->assertNull(TreasuryMovement::find($voidedLater->id)->bank_statement_line_id);
    }

    public function test_deleting_a_global_payment_after_closing_marks_the_statement_stale(): void
    {
        [$statement, $items] = $this->acceptanceScenario();
        $this->service()->closeStatement((int) $statement->id, 1, self::ADMIN);

        $clean = $this->service()->summary((int) $statement->id, self::ADMIN);
        $this->assertFalse($clean['stale']);
        $this->assertSame(0, $clean['stale_count']);
        $this->assertFalse($this->service()->getStatement((int) $statement->id, self::ADMIN)['stale']);

        // Legacy Finance deletes the payment of the matched CPE collection (not guarded in this change: open item).
        $this->db->table('global_payments')->where('id', $items['gp'])->delete();

        $summary = $this->service()->summary((int) $statement->id, self::ADMIN);
        $this->assertTrue($summary['stale']);
        $this->assertSame(1, $summary['stale_count']);
        $this->assertSame('missing', $summary['stale_matches'][0]['reason']);
        $this->assertSame('global_payment', $summary['stale_matches'][0]['type']);
        $this->assertEquals(300.0, $summary['stale_matches'][0]['expected_amount']);
        $this->assertTrue($this->service()->getStatement((int) $statement->id, self::ADMIN)['stale']);
        $this->assertTrue($this->service()->listStatements([], self::ADMIN)['rows'][0]['stale']);
        // The stored snapshot itself is untouched.
        $this->assertFalse(BankStatement::find($statement->id)->snapshot['stale']);

        // Reopening stays possible (it is how a stale reconciliation gets fixed).
        $reopened = $this->service()->reopenStatement((int) $statement->id, 1, self::ADMIN);
        $this->assertSame('draft', $reopened->status);
        $this->assertTrue($this->service()->summary((int) $statement->id, self::ADMIN)['stale']);
    }

    public function test_changed_amount_after_matching_is_stale_and_blocks_closing(): void
    {
        $gp = $this->documentPayment(300.00, '2026-11-05');
        $statement = $this->statement([['2026-11-06', 'ABONO', 300.00]], 5000.00);
        $this->service()->autoMatch((int) $statement->id, 1, self::ADMIN);

        $this->db->table('document_payments')->update(['payment' => 250.00]);

        $stale = $this->service()->summary((int) $statement->id, self::ADMIN);
        $this->assertTrue($stale['stale']);
        $this->assertSame('amount_changed', $stale['stale_matches'][0]['reason']);

        $this->assertValidationMessage(function () use ($statement) {
            $this->service()->closeStatement((int) $statement->id, 1, self::ADMIN);
        }, 'fue modificado');
    }

    public function test_movement_from_a_line_truncates_a_long_reference_to_60_characters(): void
    {
        $reference = str_repeat('ñ', 100);
        $statement = $this->statement([['2026-11-15', 'COMISION', -15.00, $reference]], 5000.00);

        $result = $this->service()->createMovementFromLine($this->lineId($statement, 1), ['kind' => 'bank_fee', 'establishment_id' => 1], 1, self::ADMIN);

        $this->assertSame(str_repeat('ñ', 60), $result['movement']->operation_number);
        $this->assertSame('matched', $this->line($statement, 1)->status);

        // MovementService also truncates defensively for any caller.
        $direct = $this->makeMovementService()->createManual([
            'kind' => 'expense', 'date' => '2026-11-16', 'amount' => 5.00, 'destination_type' => 'bank_account', 'destination_id' => 1,
            'treasury_concept_id' => 5, 'description' => 'x', 'operation_number' => str_repeat('A', 100), 'establishment_id' => 1,
        ], 1);
        $this->assertSame(str_repeat('A', 60), $direct['movement']->operation_number);
    }

    public function test_admin_default_establishment_is_the_one_of_the_statement_account(): void
    {
        $this->db->table('bank_accounts')->insert([
            'id' => 5, 'bank_id' => 1, 'description' => 'Sucursal', 'number' => '191-005', 'currency_type_id' => 'PEN', 'status' => 1,
            'initial_balance' => 0, 'show_in_documents' => 1, 'account_type' => 'checking', 'itf_exempt' => 0, 'establishment_id' => 2,
        ]);
        $this->db->table('bank_accounts')->insert([
            'id' => 6, 'bank_id' => 1, 'description' => 'Compartida', 'number' => '191-006', 'currency_type_id' => 'PEN', 'status' => 1,
            'initial_balance' => 0, 'show_in_documents' => 1, 'account_type' => 'checking', 'itf_exempt' => 0, 'establishment_id' => null,
        ]);

        // The admin belongs to establishment 1 but the account belongs to establishment 2.
        $statement = $this->statement([['2026-11-15', 'COMISION', -15.00]], 0.00, '2026-11-01', '2026-11-20', 5);
        $lineId = $this->lineId($statement, 1);

        $this->assertSame(2, $this->service()->resolveLineEstablishmentId($lineId, null, self::ADMIN));
        $result = $this->service()->createMovementFromLine($lineId, [
            'kind' => 'bank_fee',
            'establishment_id' => $this->service()->resolveLineEstablishmentId($lineId, null, self::ADMIN),
        ], 1, self::ADMIN);
        $this->assertSame(2, (int) $result['movement']->establishment_id);

        // Shared account: falls back to the user's establishment, then to the provider.
        $shared = $this->statement([['2026-11-15', 'COMISION', -5.00]], 0.00, '2026-11-01', '2026-11-20', 6);
        $sharedLine = $this->lineId($shared, 1);
        $this->assertSame(1, $this->service()->resolveLineEstablishmentId($sharedLine, null, self::ADMIN));
        $this->assertSame(7, $this->service()->resolveLineEstablishmentId($sharedLine, null, ['is_admin' => true, 'user_id' => 1, 'establishment_id' => null], function () {
            return 7;
        }));
        // An explicit request wins for admins; non-admins are always confined.
        $this->assertSame(2, $this->service()->resolveLineEstablishmentId($sharedLine, 2, self::ADMIN));
        $this->assertSame(1, $this->service()->resolveLineEstablishmentId($sharedLine, 2, $this->user(1)));
    }

    public function test_statement_imported_with_accepted_imbalance_is_flagged_with_its_gap(): void
    {
        $balanced = $this->statement([['2026-11-05', 'A', 100.00]], 5000.00);
        $this->assertFalse($this->service()->getStatement((int) $balanced->id, self::ADMIN)['imported_unbalanced']);
        $this->assertNull($this->service()->getStatement((int) $balanced->id, self::ADMIN)['unbalanced_gap']);

        $flagged = $this->statement([['2026-12-05', 'A', 100.00]], 5000.00, '2026-12-01', '2026-12-20', 1, self::ADMIN, ['closing_balance' => 5200.00, 'accept_unbalanced' => true]);
        $detail = $this->service()->getStatement((int) $flagged->id, self::ADMIN);
        $this->assertTrue($detail['imported_unbalanced']);
        $this->assertEquals(100.0, $detail['unbalanced_gap']);
        $this->assertTrue($this->service()->summary((int) $flagged->id, self::ADMIN)['imported_unbalanced']);

        // accept_unbalanced on a balanced file does not flag anything.
        $accepted = $this->statement([['2026-10-06', 'B', 10.00]], 100.00, '2026-10-01', '2026-10-20', 2, self::ADMIN, ['accept_unbalanced' => true]);
        $this->assertFalse((bool) BankStatement::find($accepted->id)->imported_unbalanced);
    }
}
