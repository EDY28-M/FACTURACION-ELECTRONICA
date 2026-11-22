<?php

namespace Tests\Unit;

use App\CoreFacturalo\Helpers\Sunat\SendDeadline;
use App\Exceptions\Handler;
use App\Http\Controllers\Tenant\Api\DocumentController;
use App\Models\Tenant\Company;
use App\Models\Tenant\Document;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Foundation\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Integrator-facing contract of the tenant API `POST documents/send` and
 * `GET document_check_server/{external_id}` (FIX-028 / IDG2-002).
 *
 * The REAL Api\DocumentController, the REAL Facturalo (constructed against an
 * in-memory SQLite `tenant` connection with fixture company/configuration rows
 * and a temporary `tenant` storage disk) and the REAL App\Exceptions\Handler are
 * used. Nothing touches MariaDB, the network or SUNAT:
 *
 * - pre-checks (missing external_id / unknown document / non-invoice group)
 *   are answered directly by the controller as JSON 422/404/422;
 * - the post-pre-check failures come from real Facturalo code: the send-deadline
 *   guard (a deliberate plain \Exception thrown before any sender is built) and
 *   a database failure in the Facturalo constructor (QueryException). Each is rendered through the
 *   Handler with APP_DEBUG=false: deliberate business messages must reach the
 *   integrator, infrastructure errors must be masked.
 *
 * A SUNAT rejection ("Code: ...; Description: ...") cannot be produced without a
 * SOAP call; it is a plain \Exception like the deadline one and is covered by
 * ExceptionHandlerTest.
 */
class ApiDocumentSendTest extends TestCase
{
    private const GENERIC = 'Error interno del servidor';

    private $previousContainer;
    private $previousFacadeApplication;
    private $previousResolver;
    private $previousDispatcher;
    private $previousScopes;
    private $app;
    private $db;
    private $logger;
    private $storageRoot;
    private $thrown;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $this->previousFacadeApplication = Facade::getFacadeApplication();
        $this->previousResolver = Model::getConnectionResolver();
        $this->previousDispatcher = Model::getEventDispatcher();
        $this->previousScopes = (new \ReflectionProperty(Model::class, 'globalScopes'))->getValue();
        $this->thrown = null;

        $this->storageRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'api-document-send-' . bin2hex(random_bytes(6));
        mkdir($this->storageRoot, 0777, true);

        $this->logger = new class extends AbstractLogger {
            public $records = [];

            public function log($level, $message, array $context = []): void
            {
                $this->records[] = [$level, (string) $message];
            }
        };

        $this->app = new Application(realpath(__DIR__ . '/../..'));
        $this->app->instance('env', 'testing');
        $this->app->instance('config', new Repository([
            'app' => ['debug' => false],
            'filesystems' => [
                'default' => 'tenant',
                'disks' => ['tenant' => ['driver' => 'local', 'root' => $this->storageRoot]],
            ],
        ]));
        $this->app->instance(LoggerInterface::class, $this->logger);
        $this->app->instance(
            \Illuminate\Contracts\Routing\ResponseFactory::class,
            new \Illuminate\Routing\ResponseFactory(
                $this->createStub(\Illuminate\Contracts\View\Factory::class),
                $this->createStub(\Illuminate\Routing\Redirector::class)
            )
        );
        $this->app->instance('filesystem', new FilesystemManager($this->app));
        Container::setInstance($this->app);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);

        $capsule = new Manager($this->app);
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], 'tenant');
        $database = $capsule->getDatabaseManager();
        $database->setDefaultConnection('tenant');
        $this->app->instance('db', $database);
        Model::setConnectionResolver($database);
        Model::setEventDispatcher(new Dispatcher($this->app));
        $this->db = $database->connection('tenant');

        // Catalogue relations are not part of this contract; the real models are queried without them.
        Document::addGlobalScope('send-fixture', fn ($query) => $query->setEagerLoads([]));
        Company::addGlobalScope('send-fixture', fn ($query) => $query->setEagerLoads([]));

        $this->db->statement('create table documents (id integer primary key, external_id text, group_id text, document_type_id text, series text, number integer, filename text, state_type_id text, date_of_issue text, created_at text, updated_at text)');
        $this->db->statement('create table companies (id integer primary key, number text, soap_send_id text, soap_type_id text, soap_username text, soap_password text, soap_url text, certificate text, created_at text, updated_at text)');
        $this->db->statement('create table configurations (id integer primary key, sunat_alternate_server integer, created_at text, updated_at text)');
        $this->db->table('companies')->insert(['id' => 1, 'number' => '20000000001', 'soap_send_id' => '01', 'soap_type_id' => '01']);
        $this->db->table('configurations')->insert(['id' => 1, 'sunat_alternate_server' => 0]);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->deleteDirectory($this->storageRoot);
        (new \ReflectionProperty(Model::class, 'globalScopes'))->setValue(null, $this->previousScopes);
        if ($this->previousResolver) {
            Model::setConnectionResolver($this->previousResolver);
        } else {
            Model::unsetConnectionResolver();
        }
        if ($this->previousDispatcher) {
            Model::setEventDispatcher($this->previousDispatcher);
        } else {
            Model::unsetEventDispatcher();
        }
        Container::setInstance($this->previousContainer);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacadeApplication);
        parent::tearDown();
    }

    private function controller(): DocumentController
    {
        return new DocumentController();
    }

    private function request(string $method, string $uri, array $data = []): Request
    {
        return Request::create($uri, $method, $data, [], [], ['HTTP_ACCEPT' => 'application/json']);
    }

    /**
     * Runs the controller action; an escaping throwable is rendered by the real Handler,
     * exactly as the HTTP kernel does for an API request.
     */
    private function send(array $input, bool $debug = false): JsonResponse
    {
        $this->app['config']->set('app.debug', $debug);
        $request = $this->request('POST', '/api/documents/send', $input);

        try {
            $response = $this->controller()->send($request);
        } catch (Throwable $e) {
            $this->thrown = $e;

            return $this->handler()->render($request, $e);
        }

        $this->assertInstanceOf(JsonResponse::class, $response);

        return $response;
    }

    private function handler(): Handler
    {
        return new Handler($this->app);
    }

    private function body(JsonResponse $response): array
    {
        return $response->getData(true);
    }

    private function insertDocument(array $overrides = []): void
    {
        $this->db->table('documents')->insert($overrides + [
            'id' => 1,
            'external_id' => 'ext-0001',
            'group_id' => '01',
            'document_type_id' => '01',
            'series' => 'F001',
            'number' => 1,
            'filename' => '20000000001-01-F001-1',
            'state_type_id' => '01',
            'date_of_issue' => '2020-01-01',
        ]);
    }

    private function putSignedXml(string $filename = '20000000001-01-F001-1'): void
    {
        mkdir($this->storageRoot . '/signed', 0777, true);
        file_put_contents($this->storageRoot . '/signed/' . $filename . '.xml', '<Invoice/>');
    }

    private function queryCount(): int
    {
        return count($this->db->getQueryLog());
    }

    public function test_missing_or_empty_external_id_is_a_422_without_any_lookup(): void
    {
        $this->insertDocument();
        $this->db->enableQueryLog();

        foreach ([[], ['external_id' => ''], ['external_id' => null]] as $input) {
            $response = $this->send($input);
            $this->assertSame(422, $response->getStatusCode());
            $this->assertFalse($this->body($response)['success']);
            $this->assertStringContainsString('external_id', $this->body($response)['message']);
        }

        $this->assertSame(0, $this->queryCount(), 'The document table must not be queried without an external_id.');
        $this->assertNull($this->thrown);
    }

    public function test_unknown_external_id_is_a_404_with_the_message(): void
    {
        $this->insertDocument();

        $response = $this->send(['external_id' => 'does-not-exist']);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertFalse($this->body($response)['success']);
        $this->assertSame(
            'El documento con código externo does-not-exist, no se encuentra registrado.',
            $this->body($response)['message']
        );
        $this->assertNull($this->thrown, 'The pre-check answers directly instead of throwing a 500.');
    }

    public function test_non_invoice_group_is_a_422(): void
    {
        $this->insertDocument(['group_id' => '03', 'document_type_id' => '09', 'series' => 'T001']);

        $response = $this->send(['external_id' => 'ext-0001']);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertFalse($this->body($response)['success']);
        $this->assertSame(
            'El tipo de documento 09 es inválido, no es posible enviar.',
            $this->body($response)['message']
        );
        $this->assertNull($this->thrown);
    }

    public function test_expired_send_deadline_reaches_the_integrator_in_production(): void
    {
        $this->insertDocument();
        $this->putSignedXml();

        $response = $this->send(['external_id' => 'ext-0001'], false);

        // Real Facturalo::assertSendDeadline() threw a deliberate plain \Exception before any SOAP sender existed.
        $this->assertNotNull($this->thrown);
        $this->assertSame(\Exception::class, get_class($this->thrown));
        $this->assertSame(500, $response->getStatusCode());
        $this->assertFalse($this->body($response)['success']);
        $this->assertSame(SendDeadline::message('2020-01-01'), $this->body($response)['message']);
        $this->assertNotSame(self::GENERIC, $this->body($response)['message']);
        $this->assertArrayNotHasKey('file', $this->body($response));
        $this->assertArrayNotHasKey('line', $this->body($response));
        $this->assertSame('01', $this->db->table('documents')->where('id', 1)->value('state_type_id'));
    }

    public function test_missing_signed_xml_is_not_an_error_by_itself_and_still_hits_the_deadline_guard(): void
    {
        // Documents the real storage behaviour: the tenant disk returns null for a missing file
        // (no exception), so loadXmlSigned() passes and the deadline guard answers first.
        $this->insertDocument();

        $response = $this->send(['external_id' => 'ext-0001'], false);

        $this->assertSame(\Exception::class, get_class($this->thrown));
        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame(SendDeadline::message('2020-01-01'), $this->body($response)['message']);
    }

    public function test_database_failure_inside_facturalo_is_masked_in_production_and_not_leaked(): void
    {
        $this->insertDocument();
        $this->putSignedXml();
        $this->db->statement('drop table companies');

        $response = $this->send(['external_id' => 'ext-0001'], false);

        $this->assertInstanceOf(QueryException::class, $this->thrown);
        $this->assertStringContainsString('companies', $this->thrown->getMessage());
        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame(self::GENERIC, $this->body($response)['message']);
        $this->assertStringNotContainsString('companies', $response->getContent());
        $this->assertStringNotContainsString('select', strtolower($response->getContent()));
        $this->assertArrayNotHasKey('file', $this->body($response));
    }

    public function test_debug_mode_keeps_the_same_business_message_and_adds_location(): void
    {
        $this->insertDocument();
        $this->putSignedXml();

        $response = $this->send(['external_id' => 'ext-0001'], true);

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame(SendDeadline::message('2020-01-01'), $this->body($response)['message']);
        $this->assertArrayHasKey('file', $this->body($response));
    }

    public function test_check_server_unknown_external_id_is_a_404_without_touching_the_offline_server(): void
    {
        $this->insertDocument();
        $this->db->enableQueryLog();

        $response = $this->controller()->documentCheckServer('does-not-exist');

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(404, $response->getStatusCode());
        $this->assertFalse($this->body($response)['success']);
        $this->assertSame(
            'El documento con código externo does-not-exist, no se encuentra registrado.',
            $this->body($response)['message']
        );
        // Only the single document lookup ran; no storage disk content exists and no HTTP client is involved.
        $this->assertSame(1, $this->queryCount());
        $this->assertSame([], glob($this->storageRoot . '/*'));
    }

    public function test_check_server_known_non_accepted_document_returns_state_without_reading_the_cdr(): void
    {
        $this->insertDocument();

        $result = $this->controller()->documentCheckServer('ext-0001');

        $this->assertSame(['success' => true, 'state_type_id' => '01', 'file_cdr' => null], $result);
    }
}
