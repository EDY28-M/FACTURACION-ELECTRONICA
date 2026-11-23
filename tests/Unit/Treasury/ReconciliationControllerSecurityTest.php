<?php

namespace Tests\Unit\Treasury;

use App\Support\Access\AccessMap;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Treasury\Http\Controllers\ReconciliationController;
use Modules\Treasury\Services\ReconciliationService;
use Modules\Treasury\Support\StatementParser;
use PHPUnit\Framework\TestCase;

/**
 * Phase 5 access control: every reconciliation route has a treasury rule, and the controller scopes by establishment
 * (non-admins confined, no establishment denied, reopening admin only).
 */
class ReconciliationControllerSecurityTest extends TestCase
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

    private function controller(): ReconciliationController
    {
        return new ReconciliationController(
            new ReconciliationService($this->makeMovementService(), $this->makeBalanceService()),
            new StatementParser()
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

    private function seedStatement(int $accountId, string $from = '2026-11-01'): int
    {
        $service = new ReconciliationService($this->makeMovementService(), $this->makeBalanceService());

        return (int) $service->createStatement($accountId, [
            'period_from' => $from,
            'period_to' => date('Y-m-d', strtotime($from . ' +10 days')),
            'opening_balance' => 0,
            'closing_balance' => 10,
        ], [['line_no' => 1, 'date' => $from, 'description' => 'ABONO', 'reference' => null, 'amount' => 10.00]], null, null, 1, ['is_admin' => true])->id;
    }

    public function test_every_reconciliation_route_has_a_treasury_rule_and_denies_other_modules(): void
    {
        $map = new AccessMap(require __DIR__ . '/../../../config/permissions.php');

        $expected = [
            ['GET', 'treasury/reconciliation'],
            ['GET', 'treasury/reconciliation/tables'],
            ['GET', 'treasury/reconciliation/statements'],
            ['GET', 'treasury/reconciliation/statements/1'],
            ['GET', 'treasury/reconciliation/statements/1/lines'],
            ['GET', 'treasury/reconciliation/statements/1/candidates'],
            ['GET', 'treasury/reconciliation/statements/1/summary'],
            ['POST', 'treasury/reconciliation/preview'],
            ['POST', 'treasury/reconciliation/statements'],
            ['POST', 'treasury/reconciliation/statements/1/discard'],
            ['POST', 'treasury/reconciliation/statements/1/auto-match'],
            ['POST', 'treasury/reconciliation/statements/1/close'],
            ['POST', 'treasury/reconciliation/statements/1/reopen'],
            ['POST', 'treasury/reconciliation/lines/1/match'],
            ['POST', 'treasury/reconciliation/lines/1/unmatch'],
            ['POST', 'treasury/reconciliation/lines/1/ignore'],
            ['POST', 'treasury/reconciliation/lines/1/unignore'],
            ['POST', 'treasury/reconciliation/lines/1/create-movement'],
            ['POST', 'treasury/reconciliation/mappings'],
            ['POST', 'treasury/reconciliation/mappings/1/delete'],
        ];

        $source = file_get_contents(__DIR__ . '/../../../modules/Treasury/Routes/web.php');
        preg_match_all("/Route::(get|post|put|patch|delete)\(\s*'(reconciliation[^']*)'/", $source, $matches, PREG_SET_ORDER);
        $declared = [];
        foreach ($matches as $m) {
            $declared[] = strtoupper($m[1]) . ' treasury/' . preg_replace('/\{[^}]+\}/', '1', $m[2]);
        }

        foreach ($expected as [$method, $path]) {
            $this->assertContains($method . ' ' . $path, $declared, "Route not declared: {$method} {$path}");

            $rule = $map->resolve($path, $method);
            $this->assertNotNull($rule, "Missing permission rule for {$method} {$path}");
            $this->assertSame('treasury', $rule['module']);
            $this->assertTrue($rule['ajax']);

            foreach ([false, true] as $isAjax) {
                $denied = $map->decide($path, false, ['dashboard', 'finance', 'configuration', 'documents'], [], $isAjax, $method);
                $this->assertFalse($denied['allowed'], "Other modules must be denied on {$method} {$path}");
                $this->assertSame('module', $denied['reason']);
            }
            $this->assertTrue($map->decide($path, false, ['treasury'], [], true, $method)['allowed'], "Treasury user denied on {$method} {$path}");
        }

        $this->assertCount(count($expected), $declared, 'A reconciliation route is not covered by this test.');
    }

    public function test_tables_and_statement_list_are_scoped_to_the_users_establishment(): void
    {
        $this->db->table('bank_accounts')->insert([
            'id' => 5, 'bank_id' => 1, 'description' => 'Sucursal', 'number' => '191-005', 'currency_type_id' => 'PEN', 'status' => 1,
            'initial_balance' => 0, 'show_in_documents' => 1, 'account_type' => 'checking', 'itf_exempt' => 0, 'establishment_id' => 2,
        ]);
        $own = $this->seedStatement(1);
        $foreign = $this->seedStatement(5);

        $controller = $this->controller();

        $this->auth->currentUser = $this->user(2, 'seller', 1);
        $tables = $controller->tables()->getData(true);
        $ids = array_column($tables['accounts'], 'id');
        $this->assertContains(1, $ids);
        $this->assertNotContains(5, $ids);
        $this->assertFalse($tables['is_admin']);
        $this->assertSame(1, $tables['default_establishment_id']);

        $list = $controller->statements($this->validatedRequest([]))->getData(true);
        $this->assertSame([$own], array_column($list['data']['rows'], 'id'));

        try {
            $controller->show($foreign);
            $this->fail('Cross-establishment access must be denied.');
        } catch (AuthorizationException $e) {
            $this->assertStringContainsString('otra sede', $e->getMessage());
        }

        $this->auth->currentUser = $this->user(1, 'admin', 1);
        $adminTables = $controller->tables()->getData(true);
        $this->assertContains(5, array_column($adminTables['accounts'], 'id'));
        $this->assertTrue($adminTables['is_admin']);
        $this->assertCount(2, $controller->statements($this->validatedRequest([]))->getData(true)['data']['rows']);
    }

    public function test_user_without_establishment_sees_and_reaches_nothing(): void
    {
        $statement = $this->seedStatement(1);
        $controller = $this->controller();

        $this->auth->currentUser = $this->user(3, 'seller', null);
        $this->assertSame([], $controller->tables()->getData(true)['accounts']);
        $this->assertSame([], $controller->statements($this->validatedRequest([]))->getData(true)['data']['rows']);

        $this->expectException(AuthorizationException::class);
        $controller->summary($statement);
    }

    public function test_reopen_is_admin_only_at_the_controller(): void
    {
        $statement = $this->seedStatement(1);
        $controller = $this->controller();

        $this->auth->currentUser = $this->user(2, 'seller', 1);
        $response = $controller->reopen($statement);
        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('administrador', $response->getData(true)['message']);

        // An admin gets past the guard; the draft statement is simply not closed (validation, not a 403).
        $this->auth->currentUser = $this->user(1, 'admin', 1);
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $controller->reopen($statement);
    }
}
