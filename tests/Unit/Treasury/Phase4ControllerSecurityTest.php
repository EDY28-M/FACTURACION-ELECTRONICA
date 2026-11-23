<?php

namespace Tests\Unit\Treasury;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Treasury\Http\Controllers\DetractionController;
use Modules\Treasury\Http\Controllers\WithholdingController;
use Modules\Treasury\Http\Requests\DepositDetractionRequest;
use Modules\Treasury\Http\Requests\ReleaseDetractionRequest;
use Modules\Treasury\Models\DetractionRecord;
use Modules\Treasury\Services\TaxRateRepository;
use PHPUnit\Framework\TestCase;

/**
 * Record-level authorization on the Phase 4 controllers (non-admin ownership, admin-only fund moves,
 * emission options hidden without agent flags).
 */
class Phase4ControllerSecurityTest extends TestCase
{
    use Phase4Harness;

    private $auth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootHarness();

        $this->app->instance(ResponseFactory::class, new class {
            public function json($data = [], $status = 200, array $headers = [], $options = 0): JsonResponse
            {
                return new JsonResponse($data, $status, $headers, $options);
            }
        });
        $this->auth = new class {
            public $currentUser;

            public function user()
            {
                return $this->currentUser;
            }

            public function id()
            {
                return $this->currentUser ? $this->currentUser->id : null;
            }
        };
        $this->app->instance(AuthFactory::class, $this->auth);
    }

    protected function tearDown(): void
    {
        $this->tearDownHarness();
        parent::tearDown();
    }

    private function user(int $id, string $type, ?int $establishmentId): object
    {
        $user = new \stdClass();
        $user->id = $id;
        $user->type = $type;
        $user->establishment_id = $establishmentId;

        return $user;
    }

    private function validatedRequest(array $params): Request
    {
        return new class($params) extends Request {
            public function __construct(array $params)
            {
                parent::__construct($params);
            }

            public function validate(array $rules, ...$args)
            {
                return app('validator')->make($this->all(), $rules)->validate();
            }
        };
    }

    public function test_detraction_controller_scopes_records_and_guards_deposits_and_releases(): void
    {
        $this->insertDocument(['number' => '1', 'user_id' => 1, 'detraction' => $this->detractionJson(120.00)]);
        $this->insertDocument(['number' => '2', 'user_id' => 2, 'detraction' => $this->detractionJson(60.00)]);
        $service = $this->makeDetractionService();
        $controller = new DetractionController($service);

        // Seller 2 only sees their own record, even when spoofing another establishment.
        $this->auth->currentUser = $this->user(2, 'seller', 1);
        $data = $controller->records($this->validatedRequest(['side' => 'receivable', 'establishment_id' => 2]))->getData(true);
        $this->assertTrue($data['success']);
        $this->assertSame(1, $data['data']['meta']['total']);
        $this->assertSame('F001-2', $data['data']['rows'][0]['number_full']);

        // Admin sees both.
        $this->auth->currentUser = $this->user(1, 'admin', 1);
        $adminData = $controller->records($this->validatedRequest(['side' => 'receivable']))->getData(true);
        $this->assertSame(2, $adminData['data']['meta']['total']);

        $foreignRecord = DetractionRecord::query()->where('user_id', 1)->firstOrFail();

        $depositRequest = new class(['date' => '2026-11-05']) extends DepositDetractionRequest {
            private $payload;

            public function __construct(array $payload)
            {
                $this->payload = $payload;
            }

            public function validated($key = null, $default = null)
            {
                return $this->payload;
            }
        };

        // Seller 2 cannot register a deposit on admin's record (AuthorizationException -> HTTP 403).
        $this->auth->currentUser = $this->user(2, 'seller', 1);
        try {
            $controller->deposit($depositRequest, (int) $foreignRecord->id);
            $this->fail('Expected AuthorizationException for a foreign record.');
        } catch (AuthorizationException $e) {
            $this->assertStringContainsString('permiso', $e->getMessage());
        }

        // Account of another establishment is refused with 403 before touching the record.
        $this->db->table('bank_accounts')->where('id', 1)->update(['establishment_id' => 2]);
        $otherEstRequest = new class(['date' => '2026-11-05', 'bank_account_id' => 1]) extends DepositDetractionRequest {
            private $payload;

            public function __construct(array $payload)
            {
                $this->payload = $payload;
            }

            public function validated($key = null, $default = null)
            {
                return $this->payload;
            }
        };
        $ownRecord = DetractionRecord::query()->where('user_id', 2)->firstOrFail();
        $this->assertSame(403, $controller->deposit($otherEstRequest, (int) $ownRecord->id)->getStatusCode());

        // Releasing funds is admin only.
        $releaseRequest = new class(['date' => '2026-11-07', 'origin_id' => 4, 'target_id' => 2, 'amount' => 10]) extends ReleaseDetractionRequest {
            private $payload;

            public function __construct(array $payload)
            {
                $this->payload = $payload;
            }

            public function validated($key = null, $default = null)
            {
                return $this->payload;
            }
        };
        $this->assertSame(403, $controller->release($releaseRequest)->getStatusCode());
    }

    public function test_withholding_controller_hides_emission_without_flags_and_restricts_settlement_to_admin(): void
    {
        $controller = new WithholdingController($this->makeRetentionService(), new TaxRateRepository());

        $this->auth->currentUser = $this->user(2, 'seller', 1);
        $tables = $controller->tables()->getData(true);
        $this->assertFalse($tables['flags']['is_retention_agent']);
        $this->assertFalse($tables['flags']['is_perception_agent']);
        $this->assertNull($tables['retention_emit_url']);
        $this->assertNull($tables['perception_emit_url']);
        $this->assertNull($tables['retention_rule']);
        $this->assertSame([], $tables['perception_rates']);

        $this->db->table('companies')->update(['is_retention_agent' => 1, 'is_perception_agent' => 1]);
        $enabled = $controller->tables()->getData(true);
        $this->assertSame('/retentions/create', $enabled['retention_emit_url']);
        $this->assertSame('/perceptions/create', $enabled['perception_emit_url']);
        $this->assertSame(0.03, $enabled['retention_rule']['rate']);
        $this->assertArrayHasKey('general', $enabled['perception_rates']);

        $settle = $this->validatedRequest(['ids' => [1], 'movement_id' => 1]);
        $this->assertSame(403, $controller->settle($settle)->getStatusCode());

        // Offers on someone else's purchase are refused for a non-admin.
        $purchase = $this->insertPurchase(['user_id' => 1, 'total' => 1000.00]);
        $this->expectException(AuthorizationException::class);
        $controller->retentionOffers($purchase);
    }

    public function test_detraction_controller_resolves_and_reverts_only_accounts_of_the_users_establishment(): void
    {
        $this->db->table('bank_accounts')->insert([
            'id' => 5, 'bank_id' => 2, 'description' => 'BN Sucursal', 'number' => '00-999-000111', 'currency_type_id' => 'PEN',
            'status' => 1, 'initial_balance' => 0, 'show_in_documents' => 0, 'account_type' => 'detraction_bn', 'itf_exempt' => 1, 'establishment_id' => 2,
        ]);
        $this->db->table('users')->insert(['id' => 3, 'name' => 'Vendedor 2', 'establishment_id' => 2]);
        $this->insertDocument(['number' => '1', 'user_id' => 3, 'establishment_id' => 2, 'detraction' => $this->detractionJson(120.00)]);
        $service = $this->makeDetractionService();
        $service->syncFromSources();
        $controller = new DetractionController($service);
        $record = DetractionRecord::query()->where('user_id', 3)->firstOrFail();

        $request = new class(['date' => '2026-11-05']) extends DepositDetractionRequest {
            private $payload;

            public function __construct(array $payload)
            {
                $this->payload = $payload;
            }

            public function validated($key = null, $default = null)
            {
                return $this->payload;
            }
        };

        // No account sent: the resolver is scoped to the seller's establishment (account 5, never account 4).
        $this->auth->currentUser = $this->user(3, 'seller', 2);
        $this->assertTrue($controller->deposit($request, (int) $record->id)->getData(true)['success']);
        $this->assertSame(5, (int) \Modules\Treasury\Models\TreasuryMovement::query()->firstOrFail()->destination_id);

        // The admin reverts and re-deposits into establishment 1's account; the seller of establishment 2
        // cannot revert that deposit.
        $service->revertDeposit($record->id, 'Reversa admin', 1, ['is_admin' => true]);
        $service->registerDeposit($record->id, ['date' => '2026-11-05', 'bank_account_id' => 4], 1, ['is_admin' => true]);
        try {
            $controller->revert($this->validatedRequest(['void_reason' => 'Intento']), (int) $record->id);
            $this->fail('Expected AuthorizationException when reverting another establishment account.');
        } catch (AuthorizationException $e) {
            $this->assertStringContainsString('otra sede', $e->getMessage());
        }
        $this->assertSame(DetractionRecord::STATUS_RECEIVED, $record->fresh()->status);
    }

    public function test_non_admin_without_establishment_is_denied_on_phase4_controllers(): void
    {
        $this->db->table('users')->insert(['id' => 4, 'name' => 'Sin sede', 'establishment_id' => null]);
        $this->insertDocument(['number' => '1', 'user_id' => 4, 'establishment_id' => null, 'detraction' => $this->detractionJson(120.00)]);
        $service = $this->makeDetractionService();
        $service->syncFromSources();
        $controller = new DetractionController($service);
        $record = DetractionRecord::query()->firstOrFail();

        $request = new class(['date' => '2026-11-05']) extends DepositDetractionRequest {
            private $payload;

            public function __construct(array $payload)
            {
                $this->payload = $payload;
            }

            public function validated($key = null, $default = null)
            {
                return $this->payload;
            }
        };

        $this->auth->currentUser = $this->user(4, 'seller', null);
        $this->expectException(AuthorizationException::class);
        $controller->deposit($request, (int) $record->id);
    }
}
