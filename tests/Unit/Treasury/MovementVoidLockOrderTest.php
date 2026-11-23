<?php

namespace Tests\Unit\Treasury;

use Illuminate\Validation\ValidationException;
use Modules\Treasury\Models\TreasuryMovement;
use Modules\Treasury\Services\MovementService;
use PHPUnit\Framework\TestCase;

/**
 * W5: MovementService::void() learns the destinations with an unlocked read, locks them first (global lock order:
 * account -> movement) and fails cleanly if the destinations changed before the movement rows were locked.
 */
class MovementVoidLockOrderTest extends TestCase
{
    use Phase4Harness;

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

    private function expense(): TreasuryMovement
    {
        return $this->makeMovementService()->createManual([
            'kind' => 'expense', 'date' => '2026-11-10', 'amount' => 50.00, 'destination_type' => 'bank_account', 'destination_id' => 1,
            'treasury_concept_id' => 5, 'description' => 'x', 'establishment_id' => 1,
        ], 1)['movement'];
    }

    private function makeRecordingService(array $destinationsToReport): MovementService
    {
        $repo = new \Modules\Treasury\Services\TaxRateRepository();
        $bancarization = new \Modules\Treasury\Services\BancarizationChecker($repo);

        return new class($repo, new \Modules\Treasury\Services\ItfCalculator($repo), $bancarization, new \Modules\Treasury\Services\PaymentEvidenceService($bancarization), $destinationsToReport) extends MovementService {
            private $report;

            public function __construct($repo, $itf, $bancarization, $evidence, array $report)
            {
                parent::__construct($repo, $itf, $bancarization, $evidence);
                $this->report = $report;
            }

            protected function collectVoidDestinations(int $movementId): array
            {
                return $this->report;
            }
        };
    }

    public function test_void_fails_cleanly_when_the_destinations_changed_between_the_read_and_the_row_locks(): void
    {
        $movement = $this->expense();

        // The unlocked read claims the movement lives in account 2, but it is really in account 1.
        $service = $this->makeRecordingService(['App\\Models\\Tenant\\BankAccount:2' => ['App\\Models\\Tenant\\BankAccount', 2]]);

        try {
            $service->void((int) $movement->id, 'motivo de prueba', 1);
            $this->fail('Expected the void to be refused.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('cambió', implode(' ', $e->errors()['movement']));
        }
        $this->assertSame('active', TreasuryMovement::find($movement->id)->state);
    }

    public function test_void_succeeds_when_the_pre_read_matches_the_locked_destinations(): void
    {
        $movement = $this->expense();
        $service = $this->makeMovementService();

        $result = $service->void((int) $movement->id, 'motivo de prueba', 1);

        $this->assertSame(1, $result['count']);
        $this->assertSame('voided', TreasuryMovement::find($movement->id)->state);
    }

    public function test_void_of_an_unknown_movement_still_raises_model_not_found(): void
    {
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $this->makeMovementService()->void(9999, 'motivo de prueba', 1);
    }
}
