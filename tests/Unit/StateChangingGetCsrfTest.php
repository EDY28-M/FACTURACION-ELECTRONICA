<?php

namespace Tests\Unit;

use App\Http\Middleware\RequireCsrfForStateChangingGet;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use PHPUnit\Framework\TestCase;

class StateChangingGetCsrfTest extends TestCase
{
    private $previousContainer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousContainer = Container::getInstance();
        $app = new Container();
        $session = new Store('test', new ArraySessionHandler(120));
        $session->start();
        $session->put('_token', 'test-token');
        $app->instance('session', $session);
        Container::setInstance($app);
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    private function handle(Request $request): Response
    {
        $middleware = new RequireCsrfForStateChangingGet();

        return $middleware->handle($request, function () {
            return new Response('ok');
        });
    }

    public function test_state_changing_get_without_csrf_is_rejected(): void
    {
        $response = $this->handle(Request::create('/purchases/anular/1', 'GET'));

        $this->assertSame(419, $response->getStatusCode());
    }

    public function test_state_changing_get_with_csrf_reaches_controller(): void
    {
        $request = Request::create('/cash/close/1', 'GET');
        $request->headers->set('X-CSRF-TOKEN', 'test-token');

        $response = $this->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('ok', $response->getContent());
    }

    public function test_normal_get_and_post_are_not_affected(): void
    {
        $this->assertSame(200, $this->handle(Request::create('/dashboard', 'GET'))->getStatusCode());
        $this->assertSame(200, $this->handle(Request::create('/purchases/anular/1', 'POST'))->getStatusCode());
    }

    public function test_head_cannot_bypass_csrf(): void
    {
        $this->assertSame(419, $this->handle(Request::create('/cash/close/1', 'HEAD'))->getStatusCode());
    }

    public function test_other_guarded_actions_are_covered(): void
    {
        foreach ([
            '/documents/re_store/1',
            '/documents/change_to_registered_status/1',
            '/expenses/1/voided',
            '/contracts/voided/1',
            '/contracts/state-type/05/1',
            '/quotations/state-type/05/1',
            '/sale-notes/changed/1',
            '/auto-update/artisan/migrate',
        ] as $path) {
            $this->assertSame(419, $this->handle(Request::create($path, 'GET'))->getStatusCode(), $path);
        }
    }
}
