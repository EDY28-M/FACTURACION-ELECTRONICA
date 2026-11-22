<?php

namespace Tests\Unit;

use App\CoreFacturalo\WS\Gre\Exceptions\GreException;
use App\Exceptions\Handler;
use ErrorException;
use Exception;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use InvalidArgumentException;
use Modules\Sale\Services\ContractAlreadyBilledException;
use PDOException;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;
use TypeError;

/**
 * The production (APP_DEBUG=false) JSON error contract: deliberate business
 * messages reach the UI, infrastructure errors are masked but still reported.
 */
class ExceptionHandlerTest extends TestCase
{
    private const GENERIC = 'Error interno del servidor';

    private $previousContainer;
    private $previousFacadeApplication;
    private $app;
    private $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $this->previousFacadeApplication = Facade::getFacadeApplication();

        $this->logger = new class extends AbstractLogger {
            public $records = [];

            public function log($level, $message, array $context = []): void
            {
                $this->records[] = [$level, (string) $message];
            }
        };

        $this->app = new Application(dirname(__DIR__, 2));
        $this->app->instance('config', new Repository(['app' => ['debug' => false]]));
        $this->app->instance(LoggerInterface::class, $this->logger);
        // response()->json() only needs a ResponseFactory; no view or redirect is rendered here.
        $this->app->instance(
            \Illuminate\Contracts\Routing\ResponseFactory::class,
            new \Illuminate\Routing\ResponseFactory(
                $this->createStub(\Illuminate\Contracts\View\Factory::class),
                $this->createStub(\Illuminate\Routing\Redirector::class)
            )
        );
        Container::setInstance($this->app);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previousContainer);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacadeApplication);
        parent::tearDown();
    }

    private function render(Throwable $exception, bool $debug = false): JsonResponse
    {
        $this->app['config']->set('app.debug', $debug);
        $request = Request::create('/api/documents', 'POST', [], [], [], ['HTTP_ACCEPT' => 'application/json']);

        return (new Handler($this->app))->render($request, $exception);
    }

    private function body(JsonResponse $response): array
    {
        return $response->getData(true);
    }

    private function assertMessageKept(Throwable $exception, string $expected): void
    {
        $response = $this->render($exception);
        $this->assertSame(500, $response->getStatusCode());
        $this->assertFalse($this->body($response)['success']);
        $this->assertSame($expected, $this->body($response)['message']);
        $this->assertArrayNotHasKey('file', $this->body($response));
        $this->assertArrayNotHasKey('line', $this->body($response));
    }

    private function assertMasked(Throwable $exception, string $secret): void
    {
        $response = $this->render($exception);
        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame(self::GENERIC, $this->body($response)['message']);
        $this->assertStringNotContainsString($secret, $response->getContent());
        $this->assertArrayNotHasKey('file', $this->body($response));
    }

    public function test_plain_exception_business_message_is_preserved(): void
    {
        $this->assertMessageKept(new Exception('Plazo de envío vencido'), 'Plazo de envío vencido');
    }

    public function test_sunat_rejection_message_is_preserved(): void
    {
        $message = 'Code: 2324; Description: El comprobante fue informado previamente en una baja';
        $this->assertMessageKept(new Exception($message), $message);
    }

    public function test_invalid_argument_exception_is_preserved(): void
    {
        $this->assertMessageKept(new InvalidArgumentException('Serie no válida'), 'Serie no válida');
    }

    public function test_app_and_modules_namespaced_exceptions_are_preserved(): void
    {
        $this->assertMessageKept(new GreException('SUNAT GRE rechazó el envío'), 'SUNAT GRE rechazó el envío');
        $this->assertMessageKept(new ContractAlreadyBilledException('Periodo ya facturado'), 'Periodo ya facturado');
    }

    public function test_query_exception_is_masked_and_sql_not_leaked(): void
    {
        $sql = 'select * from secret_table where token = ?';
        $exception = new QueryException('tenant', $sql, ['abc'], new PDOException('SQLSTATE[42S02]: Base table or view not found'));

        $this->assertStringContainsString('secret_table', $exception->getMessage());
        $this->assertMasked($exception, 'secret_table');
        $this->assertStringNotContainsString('SQLSTATE', $this->render($exception)->getContent());
    }

    public function test_pdo_error_and_type_exceptions_are_masked(): void
    {
        $this->assertMasked(new PDOException('SQLSTATE[HY000] [2002] Connection refused to db-host-9'), 'db-host-9');
        $this->assertMasked(new ErrorException('Undefined variable $internalToken in /srv/app.php'), 'internalToken');
        $this->assertMasked(new TypeError('Argument #1 must be of type Foo, null given in /srv/app.php'), '/srv/app.php');
        $this->assertMasked(new \Error('Call to undefined method Secret::boom()'), 'Secret');
    }

    public function test_vendor_runtime_exceptions_are_masked(): void
    {
        $this->assertMasked(new RuntimeException('cURL error 6: Could not resolve host internal.sunat-proxy'), 'internal.sunat-proxy');
        $this->assertMasked(new \GuzzleHttp\Exception\TransferException('Connection timed out to 10.0.0.5'), '10.0.0.5');
    }

    public function test_http_exception_keeps_status_and_message(): void
    {
        $response = $this->render(new HttpException(503, 'Servicio en mantenimiento'));
        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame('Servicio en mantenimiento', $this->body($response)['message']);

        $response = $this->render(new HttpException(418, 'Soy una tetera'));
        $this->assertSame(418, $response->getStatusCode());
        $this->assertSame('Soy una tetera', $this->body($response)['message']);
    }

    public function test_4xx_responses_are_unchanged(): void
    {
        $response = $this->render(new NotFoundHttpException('internal route detail'));
        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('No se encontró la URL especificada', $this->body($response)['message']);
    }

    public function test_debug_mode_preserves_every_message_and_adds_file_line(): void
    {
        $exceptions = [
            new Exception('negocio'),
            new QueryException('tenant', 'select 1 from secret_table', [], new PDOException('boom')),
            new PDOException('pdo detail'),
            new TypeError('type detail'),
            new RuntimeException('runtime detail'),
        ];

        foreach ($exceptions as $exception) {
            $response = $this->render($exception, true);
            $body = $this->body($response);
            $this->assertSame(500, $response->getStatusCode());
            $this->assertSame($exception->getMessage(), $body['message']);
            $this->assertSame($exception->getFile(), $body['file']);
            $this->assertSame($exception->getLine(), $body['line']);
        }
    }

    public function test_5xx_is_reported_exactly_once(): void
    {
        $handler = new Handler($this->app);
        $request = Request::create('/api/documents', 'POST', [], [], [], ['HTTP_ACCEPT' => 'application/json']);

        $masked = new RuntimeException('masked failure');
        $handler->render($request, $masked);
        $handler->render($request, $masked);
        $this->assertCount(1, $this->logger->records);

        // Already reported by the kernel before rendering: render must not log it again.
        $reported = new Exception('already reported');
        $handler->report($reported);
        $handler->render($request, $reported);
        $this->assertCount(2, $this->logger->records);

        // Business messages are shown but still reported once as 5xx.
        $handler->render($request, new Exception('Plazo de envío vencido'));
        $this->assertCount(3, $this->logger->records);
    }
}
