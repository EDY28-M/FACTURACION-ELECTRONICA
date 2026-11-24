<?php

namespace Tests\Unit\Treasury;

use App\Models\Tenant\BankAccount;
use App\Models\Tenant\DocumentPayment;
use Illuminate\Validation\ValidationException;
use Modules\Finance\Traits\FinanceTrait;
use PHPUnit\Framework\TestCase;

/**
 * Detraction (Banco de la Nación) accounts must not be offered nor accepted as destination of legacy payments.
 */
class LegacyPaymentBnGuardTest extends TestCase
{
    use Phase4Harness;

    private $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootHarness();
        $this->db->table('banks')->insertOrIgnore([['id' => 1, 'description' => 'BCP'], ['id' => 2, 'description' => 'Banco de la Nación']]);

        $this->subject = new class {
            use FinanceTrait;

            public function bankAccounts()
            {
                return self::getBankAccounts();
            }
        };
    }

    protected function tearDown(): void
    {
        $this->tearDownHarness();
        parent::tearDown();
    }

    public function test_destination_list_excludes_detraction_bn_accounts_and_keeps_the_rest(): void
    {
        $ids = collect($this->subject->bankAccounts())->pluck('id')->all();

        $this->assertSame([1, 2], $ids);
        $this->assertNotContains(4, $ids);
    }

    public function test_destination_list_keeps_accounts_with_null_account_type(): void
    {
        $this->db->table('bank_accounts')->insert([
            'id' => 9, 'bank_id' => 1, 'description' => 'Legacy', 'number' => '9', 'currency_type_id' => 'PEN', 'status' => 1,
            'initial_balance' => 0, 'show_in_documents' => 1, 'account_type' => null, 'itf_exempt' => 0, 'establishment_id' => null,
        ]);

        $this->assertContains(9, collect($this->subject->bankAccounts())->pluck('id')->all());
    }

    public function test_creating_a_payment_to_a_detraction_bn_account_is_rejected(): void
    {
        $payment = new DocumentPayment();
        $payment->id = 1;

        try {
            $this->subject->createGlobalPayment($payment, ['payment_destination_id' => 4]);
            $this->fail('A detraction_bn destination must be rejected.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Banco de la Nación', $e->errors()['payment_destination_id'][0]);
        }

        $this->assertSame(0, $this->db->table('global_payments')->count());
    }

    public function test_destination_record_resolves_regular_bank_accounts(): void
    {
        $this->assertSame(
            ['destination_id' => 1, 'destination_type' => BankAccount::class],
            $this->subject->getDestinationRecord(['payment_destination_id' => 1])
        );
    }

    public function test_a_legacy_payment_already_on_the_bn_account_stays_editable_but_nothing_can_move_there(): void
    {
        // Legacy row from before the guard: its global payment already points to the BN account (id 4).
        $this->db->table('global_payments')->insert([
            ['destination_id' => 4, 'destination_type' => BankAccount::class, 'payment_id' => 7, 'payment_type' => DocumentPayment::class],
            ['destination_id' => 1, 'destination_type' => BankAccount::class, 'payment_id' => 8, 'payment_type' => DocumentPayment::class],
        ]);

        $existing = new DocumentPayment();
        $existing->id = 7;
        $existing->exists = true;

        // Unchanged destination: allowed (the edit goes through and the row still points to the same account).
        $this->subject->createGlobalPayment($existing, ['payment_destination_id' => 4], 1);
        $rows = $this->db->table('global_payments')->where('payment_id', 7)->get();
        $this->assertCount(1, $rows);
        $this->assertSame(4, (int) $rows[0]->destination_id);

        // Changing an existing payment TO the BN account is refused and its global payment is untouched.
        $moving = new DocumentPayment();
        $moving->id = 8;
        $moving->exists = true;
        try {
            $this->subject->createGlobalPayment($moving, ['payment_destination_id' => 4], 1);
            $this->fail('Moving a payment to the BN account must be refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('payment_destination_id', $e->errors());
        }
        $this->assertSame(1, (int) $this->db->table('global_payments')->where('payment_id', 8)->value('destination_id'));

        // A brand new payment (no global payment yet) is refused too.
        $fresh = new DocumentPayment();
        $fresh->id = 9;
        $this->expectException(ValidationException::class);
        $this->subject->createGlobalPayment($fresh, ['payment_destination_id' => 4], 1);
    }
}
