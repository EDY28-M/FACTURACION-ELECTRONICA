<?php

namespace Tests\Unit\Treasury;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\Treasury\Http\Controllers\DashboardController;
use Modules\Treasury\Http\Controllers\InstrumentController;
use Modules\Treasury\Http\Requests\StoreInstrumentRequest;
use Modules\Treasury\Http\Requests\TransitionInstrumentRequest;
use Modules\Treasury\Models\TreasuryInstrument;
use Modules\Treasury\Support\InstrumentDeadlines;
use Modules\Treasury\Services\TaxRateRepository;
use Modules\Treasury\Services\TreasuryBalanceService;
use Modules\Treasury\Support\BusinessDayCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Record-level authorization on the Phase 7 controller: non-admins only see and manage their own instruments in
 * their own establishment; establishment <= 0 is denied; validation errors surface as 422 field errors.
 */
class InstrumentControllerSecurityTest extends TestCase
{
    use Phase4Harness;

    private $auth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootHarness(['2026-12-08']);

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

    private function controller(): InstrumentController
    {
        return new InstrumentController(
            $this->makeInstrumentService(['2026-12-08']),
            new InstrumentDeadlines(new TaxRateRepository(), new BusinessDayCalculator(['2026-12-08']))
        );
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

    private function storeRequest(array $payload): StoreInstrumentRequest
    {
        return new class($payload) extends StoreInstrumentRequest {
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
    }

    private function transitionRequest(array $payload): TransitionInstrumentRequest
    {
        return new class($payload) extends TransitionInstrumentRequest {
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
    }

    private function chequePayload(array $overrides = []): array
    {
        return array_merge([
            'type' => TreasuryInstrument::TYPE_CHEQUE_RECEIVED,
            'number' => 'CH-' . random_int(1000, 9999),
            'amount' => 250.00,
            'currency_type_id' => 'PEN',
            'issue_date' => '2026-11-10',
            'person_id' => 1,
        ], $overrides);
    }

    public function test_store_scopes_to_the_user_establishment_and_denies_users_without_one(): void
    {
        $controller = $this->controller();

        $this->auth->currentUser = $this->user(2, 'seller', 1);
        $response = $controller->store($this->storeRequest($this->chequePayload(['establishment_id' => 2])));
        $this->assertSame(201, $response->getStatusCode());
        $data = $response->getData(true);
        $this->assertSame(1, $data['data']['establishment_id']);
        $this->assertSame('delivered', $data['data']['status']);
        $this->assertSame('2026-12-10', $data['data']['presentation_deadline']);

        $this->auth->currentUser = $this->user(3, 'seller', null);
        $this->expectException(AuthorizationException::class);
        $controller->store($this->storeRequest($this->chequePayload()));
    }

    public function test_records_are_scoped_and_spoofing_the_establishment_filter_changes_nothing(): void
    {
        $controller = $this->controller();

        $this->auth->currentUser = $this->user(1, 'admin', 1);
        $controller->store($this->storeRequest($this->chequePayload(['number' => 'ADM-1'])));
        $this->auth->currentUser = $this->user(2, 'seller', 1);
        $controller->store($this->storeRequest($this->chequePayload(['number' => 'SEL-1'])));

        $params = ['type' => TreasuryInstrument::TYPE_CHEQUE_RECEIVED, 'establishment_id' => 2];

        $seller = $controller->records($this->validatedRequest($params))->getData(true);
        $this->assertSame(1, $seller['data']['meta']['total']);
        $this->assertSame('SEL-1', $seller['data']['rows'][0]['number']);

        $this->auth->currentUser = $this->user(1, 'admin', 1);
        $adminAll = $controller->records($this->validatedRequest(['type' => TreasuryInstrument::TYPE_CHEQUE_RECEIVED]))->getData(true);
        $this->assertSame(2, $adminAll['data']['meta']['total']);
        $adminOtherEst = $controller->records($this->validatedRequest($params))->getData(true);
        $this->assertSame(0, $adminOtherEst['data']['meta']['total']);

        $this->auth->currentUser = $this->user(3, 'seller', null);
        $nobody = $controller->records($this->validatedRequest(['type' => TreasuryInstrument::TYPE_CHEQUE_RECEIVED]))->getData(true);
        $this->assertSame(0, $nobody['data']['meta']['total']);
    }

    public function test_transition_update_and_history_of_a_foreign_instrument_are_forbidden(): void
    {
        $controller = $this->controller();

        $this->auth->currentUser = $this->user(1, 'admin', 1);
        $created = $controller->store($this->storeRequest($this->chequePayload()))->getData(true);
        $id = (int) $created['data']['id'];

        $this->auth->currentUser = $this->user(2, 'seller', 1);
        foreach ([
            function () use ($controller, $id) {
                $controller->transition($this->transitionRequest(['to' => 'deposited', 'bank_account_id' => 1]), $id);
            },
            function () use ($controller, $id) {
                $controller->update($this->storeRequest($this->chequePayload()), $id);
            },
            function () use ($controller, $id) {
                $controller->history($id);
            },
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('Expected AuthorizationException for a foreign instrument.');
            } catch (AuthorizationException $e) {
                $this->assertStringContainsString('permiso', $e->getMessage());
            }
        }

        // Untouched.
        $this->assertSame('delivered', TreasuryInstrument::find($id)->status);
    }

    public function test_transition_returns_warnings_movement_id_and_state_machine_errors(): void
    {
        $controller = $this->controller();
        $this->auth->currentUser = $this->user(1, 'admin', 1);
        $created = $controller->store($this->storeRequest($this->chequePayload(['issue_date' => '2026-09-01'])))->getData(true);
        $id = (int) $created['data']['id'];

        $deposit = $controller->transition($this->transitionRequest(['to' => 'deposited', 'bank_account_id' => 1]), $id)->getData(true);
        $this->assertTrue($deposit['success']);
        $this->assertCount(1, $deposit['warnings']);
        $this->assertNull($deposit['movement_id']);
        $this->assertSame(['collected', 'bounced'], array_column($deposit['data']['actions'], 'to'));

        $collected = $controller->transition($this->transitionRequest(['to' => 'collected']), $id)->getData(true);
        $this->assertNotNull($collected['movement_id']);

        try {
            $controller->transition($this->transitionRequest(['to' => 'deposited', 'bank_account_id' => 1]), $id);
            $this->fail('Expected the state machine to refuse.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors());
        }
    }

    public function test_tables_limit_establishments_and_accounts_for_non_admins_and_hide_the_bn_account(): void
    {
        $controller = $this->controller();
        $this->db->statement('create table cat_currency_types (id text primary key, description text, symbol text, active integer default 1)');
        $this->db->table('cat_currency_types')->insert([['id' => 'PEN', 'description' => 'Soles', 'symbol' => 'S/', 'active' => 1], ['id' => 'USD', 'description' => 'Dólares', 'symbol' => '$', 'active' => 1]]);
        $this->db->table('bank_accounts')->where('id', 2)->update(['establishment_id' => 2]);

        $this->auth->currentUser = $this->user(2, 'seller', 1);
        $seller = $controller->tables()->getData(true);
        $this->assertSame([1], array_column($seller['establishments'], 'id'));
        $this->assertSame([1], array_column($seller['accounts'], 'id')); // 2 is another site, 4 is the BN account
        $this->assertFalse($seller['is_admin']);
        $this->assertSame(1, $seller['default_establishment_id']);
        $this->assertSame(30, $seller['rules']['cheque_presentation_days']);
        $this->assertSame(15, $seller['rules']['bill_protest_days']);

        $this->auth->currentUser = $this->user(1, 'admin', 1);
        $admin = $controller->tables()->getData(true);
        $this->assertSame([1, 2], array_column($admin['accounts'], 'id'));
        $this->assertCount(4, $admin['types']);

        $this->auth->currentUser = $this->user(3, 'seller', null);
        $nobody = $controller->tables()->getData(true);
        $this->assertSame([], $nobody['establishments']);
        $this->assertSame([], $nobody['accounts']);
    }

    public function test_lookups_only_return_what_the_user_can_link(): void
    {
        $controller = $this->controller();
        $this->insertDocument(['number' => '1', 'user_id' => 1]);
        $this->insertDocument(['number' => '2', 'user_id' => 2]);
        $this->insertDocument(['number' => '3', 'user_id' => 2, 'state_type_id' => '11']);

        $params = ['type' => TreasuryInstrument::TYPE_CHEQUE_RECEIVED, 'search' => 'F001'];

        $this->auth->currentUser = $this->user(2, 'seller', 1);
        $seller = $controller->sources($this->validatedRequest($params))->getData(true);
        $this->assertSame(['F001-2'], array_column($seller['data'], 'label'));

        $this->auth->currentUser = $this->user(1, 'admin', 1);
        $admin = $controller->sources($this->validatedRequest($params))->getData(true);
        $this->assertEqualsCanonicalizing(['F001-1', 'F001-2'], array_column($admin['data'], 'label'));

        // Persons depend on the direction: customers for receivable types, suppliers for payable ones.
        $customers = $controller->persons($this->validatedRequest(['type' => TreasuryInstrument::TYPE_BILL_RECEIVABLE]))->getData(true);
        $this->assertSame(['Cliente Alfa SAC'], array_column($customers['data'], 'name'));
        $suppliers = $controller->persons($this->validatedRequest(['type' => TreasuryInstrument::TYPE_CHEQUE_ISSUED, 'search' => 'Proveedor']))->getData(true);
        $this->assertSame(['Proveedor Uno SA'], array_column($suppliers['data'], 'name'));
    }

    public function test_dashboard_exposes_instrument_alerts_scoped_to_the_user(): void
    {
        $service = $this->makeInstrumentService(['2026-12-08']);
        $due = date('Y-m-d', strtotime('+3 days'));
        $issue = date('Y-m-d', strtotime('-20 days'));
        $service->create([
            'type' => TreasuryInstrument::TYPE_BILL_RECEIVABLE, 'number' => 'L-ADM', 'amount' => 500, 'currency_type_id' => 'PEN',
            'issue_date' => $issue, 'due_date' => $due, 'person_id' => 1,
        ], 1, ['is_admin' => true, 'user_id' => 1, 'establishment_id' => 1]);
        $service->create([
            'type' => TreasuryInstrument::TYPE_BILL_RECEIVABLE, 'number' => 'L-SEL', 'amount' => 300, 'currency_type_id' => 'PEN',
            'issue_date' => $issue, 'due_date' => $due, 'person_id' => 1,
        ], 2, ['is_admin' => false, 'user_id' => 2, 'establishment_id' => 1]);

        $dashboard = new DashboardController(new TreasuryBalanceService(), null, null, $service);

        $this->auth->currentUser = $this->user(2, 'seller', 1);
        $seller = $dashboard->records($this->validatedRequest([]))->getData(true);
        $this->assertSame(1, $seller['data']['alerts']['instruments']['counts']['bill_due_soon']);
        $this->assertSame('L-SEL', $seller['data']['alerts']['instruments']['items'][0]['number']);

        $this->auth->currentUser = $this->user(1, 'admin', 1);
        $admin = $dashboard->records($this->validatedRequest([]))->getData(true);
        $this->assertSame(2, $admin['data']['alerts']['instruments']['counts']['bill_due_soon']);
        $this->assertSame(2, $admin['data']['alerts']['instruments']['total']);
    }
}
