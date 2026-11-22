<?php

namespace Tests\Unit;

use App\Models\System\Configuration;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Modules\Services\Data\ServiceData;
use PHPUnit\Framework\TestCase;

class ServiceDataProvidersTest extends TestCase
{
    private $previousContainer;
    private $previousFacade;
    private $previousResolver;
    private $previousDispatcher;
    private $db;
    private $history = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $this->previousFacade = Facade::getFacadeApplication();
        $this->previousResolver = Model::getConnectionResolver();
        $this->previousDispatcher = Model::getEventDispatcher();
        $app = new Application(dirname(__DIR__, 2));
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);
        $app->instance('config', new Repository(['configuration' => ['api_service_url' => null, 'api_service_token' => null]]));
        $app->instance('events', new Dispatcher($app));
        $capsule = new Manager($app);
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], 'system');
        $manager = $capsule->getDatabaseManager();
        $manager->setDefaultConnection('system');
        $app->instance('db', $manager);
        Model::setConnectionResolver($manager);
        Model::setEventDispatcher(new Dispatcher($app));
        $this->db = $manager->connection('system');
        $this->db->statement('create table configurations (id integer primary key, url_apiruc text, token_apiruc text, secondary_url_apiruc text, secondary_token_apiruc text, apiruc_primary text default \'primary\')');
    }

    protected function tearDown(): void
    {
        ServiceData::setHttpHandler(null);
        if ($this->previousResolver) { Model::setConnectionResolver($this->previousResolver); }
        else { Model::unsetConnectionResolver(); }
        if ($this->previousDispatcher) { Model::setEventDispatcher($this->previousDispatcher); }
        else { Model::unsetEventDispatcher(); }
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacade);
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    private function configure(array $overrides = [])
    {
        $this->db->table('configurations')->insert(array_merge([
            'id' => 1,
            'url_apiruc' => 'https://primary.example.test',
            'token_apiruc' => 'fixture-primary-token',
            'secondary_url_apiruc' => 'https://secondary.example.test',
            'secondary_token_apiruc' => 'fixture-secondary-token',
            'apiruc_primary' => 'primary',
        ], $overrides));
    }

    private function mock(array $responses)
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));
        ServiceData::setHttpHandler($stack);
    }

    private static function ok()
    {
        return new Response(200, [], json_encode(['success' => true, 'data' => ['nombre_o_razon_social' => 'FIXTURE SAC']]));
    }

    private static function failure($status, $message)
    {
        return new Response($status, [], json_encode(['success' => false, 'message' => $message, 'data' => null]));
    }

    public function testPrimarySuccessDoesNotCallSecondary()
    {
        $this->configure();
        $this->mock([self::ok()]);

        $result = ServiceData::service('ruc', '20131312955');

        $this->assertTrue($result['success']);
        $this->assertCount(1, $this->history);
        $request = $this->history[0]['request'];
        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('primary.example.test', $request->getUri()->getHost());
        $this->assertSame('/api/ruc/20131312955', $request->getUri()->getPath());
    }

    public function testFallsBackToSecondaryWhenPrimaryFails()
    {
        $this->configure();
        $this->mock([self::failure(429, 'Sin saldo'), self::ok()]);

        $result = ServiceData::service('ruc', '20131312955');

        $this->assertTrue($result['success']);
        $this->assertCount(2, $this->history);
        $request = $this->history[1]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('secondary.example.test', $request->getUri()->getHost());
        $this->assertSame('/ruc', $request->getUri()->getPath());
        $this->assertSame(['ruc' => '20131312955'], json_decode((string) $request->getBody(), true));
        $this->assertSame('Bearer fixture-secondary-token', $request->getHeaderLine('Authorization'));
    }

    public function testBothFailingCombinesMessagesBySlot()
    {
        $this->configure();
        $this->mock([self::failure(429, 'Sin saldo'), self::failure(404, 'No encontrado')]);

        $result = ServiceData::service('ruc', '20131312955');

        $this->assertFalse($result['success']);
        $this->assertSame('Proveedor 1: Sin saldo · Proveedor 2: No encontrado', $result['message']);
    }

    public function testSecondaryAsPrimaryIsTriedFirstAndKeepsSlotLabels()
    {
        $this->configure(['apiruc_primary' => 'secondary']);
        $this->mock([self::failure(401, 'Token inválido'), self::failure(429, 'Sin saldo')]);

        $result = ServiceData::service('dni', '46027897');

        $this->assertSame('POST', $this->history[0]['request']->getMethod());
        $this->assertSame('/dni', $this->history[0]['request']->getUri()->getPath());
        $this->assertSame('Proveedor 1: Sin saldo · Proveedor 2: Token inválido', $result['message']);
    }

    public function testSingleProviderKeepsItsOwnMessage()
    {
        $this->configure(['secondary_token_apiruc' => null]);
        $this->mock([self::failure(404, 'No encontrado')]);

        $result = ServiceData::service('ruc', '20131312955');

        $this->assertSame('No encontrado', $result['message']);
    }

    public function testLegacyInternalMarkerNoLongerDisablesProviders()
    {
        $this->configure(['token_apiruc' => 'false']);

        $providers = ServiceData::providers();

        $this->assertTrue(ServiceData::isConfigured());
        $this->assertCount(1, $providers);
        $this->assertSame('secondary', $providers[0]['slot']);
    }

    public function testSecondaryUrlDefaultsWhenEmpty()
    {
        $this->configure(['secondary_url_apiruc' => null]);

        $providers = ServiceData::providers();

        $this->assertCount(2, $providers);
        $this->assertSame('https://api.apiperu.pe', $providers[1]['url']);
    }
}
