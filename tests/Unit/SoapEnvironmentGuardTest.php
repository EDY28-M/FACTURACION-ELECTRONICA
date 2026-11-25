<?php

namespace Tests\Unit;

use App\CoreFacturalo\Facturalo;
use App\CoreFacturalo\Helpers\Sunat\SoapEnvironmentGuard;
use App\CoreFacturalo\WS\Gre\GreDispatchService;
use App\CoreFacturalo\WS\Signed\XmlSigned;
use App\Exceptions\Handler;
use App\Models\Tenant\Company;
use App\Models\Tenant\Configuration;
use App\Models\Tenant\Document;
use Exception;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Verifies environment readiness guards, certificate hardening, and JSON error
 * contract across all three SUNAT modes:
 *   - 01: Demo (SUNAT Beta & Demo + OSE)
 *   - 02: Producción (complete & incomplete)
 *   - 03: SUNAT · Interno (Notas de Venta only; no electronic CPEs)
 */
class SoapEnvironmentGuardTest extends TestCase
{
    private const GENERIC = 'Error interno del servidor';

    private $previousContainer;
    private $previousFacadeApplication;
    private $previousResolver;
    private $previousDispatcher;
    private $previousScopes;
    private $app;
    private $db;
    private $storageRoot;
    private $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $this->previousFacadeApplication = Facade::getFacadeApplication();
        $this->previousResolver = Model::getConnectionResolver();
        $this->previousDispatcher = Model::getEventDispatcher();
        $this->previousScopes = (new \ReflectionProperty(Model::class, 'globalScopes'))->getValue();

        $this->storageRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'soap-env-guard-'.bin2hex(random_bytes(6));
        mkdir($this->storageRoot, 0777, true);

        $this->logger = new class extends AbstractLogger {
            public $records = [];

            public function log($level, $message, array $context = []): void
            {
                $this->records[] = [$level, (string) $message];
            }
        };

        $this->app = new Application(realpath(__DIR__.'/../..'));
        $this->app->instance('env', 'testing');
        $this->app->instance('config', new Repository([
            'app' => ['debug' => false],
            'filesystems' => [
                'default' => 'tenant',
                'disks' => ['tenant' => ['driver' => 'local', 'root' => $this->storageRoot]],
            ],
            'tenant' => [
                'soap_stream_context_ssl' => false,
            ],
            'gre' => [
                'test' => [
                    'token_url' => null,
                    'api_url' => null,
                    'client_id' => null,
                    'client_secret' => null,
                    'username' => null,
                    'password' => null,
                ],
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
        $this->app->instance('auth', new class {
            public function check() { return true; }
            public function id() { return 1; }
            public function user() { return (object) ['id' => 1, 'establishment_id' => 1, 'type' => 'admin']; }
        });
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

        Company::addGlobalScope('soap-env-fixture', fn ($query) => $query->setEagerLoads([]));
        Document::addGlobalScope('soap-env-fixture', fn ($query) => $query->setEagerLoads([]));

        $this->db->statement(
            'create table companies (id integer primary key, number text, soap_send_id text, soap_type_id text, soap_username text, soap_password text, soap_url text, certificate text, created_at text, updated_at text)'
        );
        $this->db->statement(
            'create table configurations (id integer primary key, send_auto integer, sunat_alternate_server integer, created_at text, updated_at text)'
        );
        $this->db->table('configurations')->insert(['id' => 1, 'send_auto' => 0, 'sunat_alternate_server' => 0]);
    }

    protected function tearDown(): void
    {
        Model::setConnectionResolver($this->previousResolver);
        Model::setEventDispatcher($this->previousDispatcher);
        (new \ReflectionProperty(Model::class, 'globalScopes'))->setValue(null, $this->previousScopes);
        Container::setInstance($this->previousContainer);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacadeApplication);
        $this->removeDirectory($this->storageRoot);
        parent::tearDown();
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.DIRECTORY_SEPARATOR.$entry;
            is_dir($path) ? $this->removeDirectory($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    private function makeCompany(array $attrs): Company
    {
        $company = new Company();
        $company->forceFill($attrs);

        return $company;
    }

    private function renderThroughHandler(Throwable $exception): array
    {
        $request = Request::create('/documents', 'POST', [], [], [], ['HTTP_ACCEPT' => 'application/json']);
        $response = (new Handler($this->app))->render($request, $exception);

        return [$response->getStatusCode(), $response->getData(true)];
    }

    // -------------------------------------------------------------------------
    // Environment 01: Demo
    // -------------------------------------------------------------------------

    public function test_demo_sunat_allows_emission_and_uses_bundled_certificate(): void
    {
        $company = $this->makeCompany([
            'number' => '20000000001',
            'soap_type_id' => '01',
            'soap_send_id' => '01',
            'soap_username' => null,
            'soap_password' => null,
            'certificate' => null,
        ]);

        $this->assertSame([], SoapEnvironmentGuard::missingForEmission($company));
        SoapEnvironmentGuard::assertCanEmit($company, 'invoice', 'B001');
        SoapEnvironmentGuard::assertCanSign($company);
        SoapEnvironmentGuard::assertCanSend($company);

        $path = SoapEnvironmentGuard::certificatePath($company);
        $this->assertTrue(is_file($path), 'Bundled demo certificate.pem must exist on disk');
        $this->assertStringEndsWith('certificate.pem', $path);
    }

    public function test_demo_ose_requires_ose_credentials_url_and_certificate(): void
    {
        $incomplete = $this->makeCompany([
            'number' => '20000000001',
            'soap_type_id' => '01',
            'soap_send_id' => '02',
            'soap_username' => '',
            'soap_password' => null,
            'soap_url' => '',
            'certificate' => null,
        ]);

        $this->assertSame(
            ['usuario SOL/OSE', 'clave SOL/OSE', 'certificado digital', 'URL del OSE'],
            SoapEnvironmentGuard::missingForEmission($incomplete)
        );

        try {
            SoapEnvironmentGuard::assertCanEmit($incomplete, 'invoice', 'F001');
            $this->fail('Expected incomplete Demo+OSE to be blocked');
        } catch (Exception $e) {
            $this->assertStringContainsString('Demo (OSE)', $e->getMessage());
            $this->assertStringContainsString('certificado digital', $e->getMessage());
            $this->assertStringContainsString('URL del OSE', $e->getMessage());
        }

        $complete = $this->makeCompany([
            'number' => '20000000001',
            'soap_type_id' => '01',
            'soap_send_id' => '02',
            'soap_username' => '20000000001OSEUSER',
            'soap_password' => 'secret',
            'soap_url' => 'https://ose.example.test/billService',
            'certificate' => 'cert_demo_ose.pem',
        ]);

        $alwaysReadable = fn (string $p): bool => str_ends_with($p, 'cert_demo_ose.pem');
        $this->assertSame([], SoapEnvironmentGuard::missingForEmission($complete, $alwaysReadable));
        SoapEnvironmentGuard::assertCanEmit($complete, 'invoice', 'F001', $alwaysReadable);
    }

    // -------------------------------------------------------------------------
    // Environment 02: Producción
    // -------------------------------------------------------------------------

    public function test_production_incomplete_lists_exact_missing_fields_and_blocks_emission(): void
    {
        $company = $this->makeCompany([
            'number' => '20600000001',
            'soap_type_id' => '02',
            'soap_send_id' => '01',
            'soap_username' => null,
            'soap_password' => '',
            'certificate' => null,
        ]);

        $this->assertSame(
            ['usuario SOL', 'clave SOL', 'certificado digital'],
            SoapEnvironmentGuard::missingForEmission($company)
        );

        try {
            SoapEnvironmentGuard::assertCanEmit($company, 'invoice', 'F001');
            $this->fail('Expected incomplete Production to throw');
        } catch (Exception $e) {
            $this->assertStringContainsString('Producción', $e->getMessage());
            $this->assertStringContainsString('usuario SOL', $e->getMessage());
            $this->assertStringContainsString('clave SOL', $e->getMessage());
            $this->assertStringContainsString('certificado digital', $e->getMessage());
        }
    }

    public function test_production_with_missing_certificate_file_on_disk_is_detected(): void
    {
        $company = $this->makeCompany([
            'number' => '20600000001',
            'soap_type_id' => '02',
            'soap_send_id' => '01',
            'soap_username' => '20600000001ADMIN',
            'soap_password' => 'solpass',
            'certificate' => 'nonexistent_cert_file.pem',
        ]);

        $this->assertSame(['certificado digital'], SoapEnvironmentGuard::missingForEmission($company));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('certificado digital');
        SoapEnvironmentGuard::assertCanSign($company);
    }

    public function test_production_ose_requires_soap_url_and_complete_production_passes(): void
    {
        $missingUrl = $this->makeCompany([
            'number' => '20600000001',
            'soap_type_id' => '02',
            'soap_send_id' => '02',
            'soap_username' => '20600000001ADMIN',
            'soap_password' => 'solpass',
            'soap_url' => '',
            'certificate' => 'prod.pem',
        ]);

        $certOk = fn (string $p): bool => str_ends_with($p, 'prod.pem');
        $this->assertSame(['URL del OSE'], SoapEnvironmentGuard::missingForEmission($missingUrl, $certOk));

        $complete = $this->makeCompany([
            'number' => '20600000001',
            'soap_type_id' => '02',
            'soap_send_id' => '02',
            'soap_username' => '20600000001ADMIN',
            'soap_password' => 'solpass',
            'soap_url' => 'https://ose.example.test/billService',
            'certificate' => '../../prod.pem',
        ]);

        $this->assertSame([], SoapEnvironmentGuard::missingForEmission($complete, $certOk));
        SoapEnvironmentGuard::assertCanEmit($complete, 'invoice', 'F001', $certOk);
        SoapEnvironmentGuard::assertCanSign($complete, $certOk);
        SoapEnvironmentGuard::assertCanSend($complete, $certOk);

        // Path traversal neutralized by basename()
        $resolved = SoapEnvironmentGuard::certificatePath($complete);
        $this->assertStringNotContainsString('..', $resolved);
        $this->assertStringEndsWith(DIRECTORY_SEPARATOR.'certificates'.DIRECTORY_SEPARATOR.'prod.pem', $resolved);
    }

    // -------------------------------------------------------------------------
    // Environment 03: SUNAT · Interno
    // -------------------------------------------------------------------------

    public function test_internal_mode_blocks_all_electronic_cpe_types_but_allows_physical_dispatch(): void
    {
        $company = $this->makeCompany([
            'number' => '20600000001',
            'soap_type_id' => '03',
            'soap_send_id' => '01',
            'certificate' => null,
        ]);

        $electronicCases = [
            ['invoice', 'B001'],
            ['invoice', 'F001'],
            ['credit', 'FC01'],
            ['debit', 'FD01'],
            ['summary', null],
            ['voided', null],
            ['retention', 'R001'],
            ['perception', 'P001'],
            ['dispatch', 'T001'],
            ['dispatch', 'V001'],
        ];

        foreach ($electronicCases as [$type, $series]) {
            try {
                SoapEnvironmentGuard::assertCanEmit($company, $type, $series);
                $this->fail("Expected internal mode to block {$type} ({$series})");
            } catch (Exception $e) {
                $this->assertSame(SoapEnvironmentGuard::INTERNAL_MODE_MESSAGE, $e->getMessage());
            }
        }

        // Physical (non-electronic) dispatch series does not sign or send to SUNAT
        SoapEnvironmentGuard::assertCanEmit($company, 'dispatch', '0001');
        $this->assertFalse(SoapEnvironmentGuard::isElectronicEmission('dispatch', '0001'));
        $this->assertTrue(SoapEnvironmentGuard::isElectronicEmission('dispatch', 'T001'));
        $this->assertTrue(SoapEnvironmentGuard::isElectronicEmission('dispatch', 'V001'));
    }

    public function test_gre_skip_reason_covers_internal_demo_and_production(): void
    {
        $internal = $this->makeCompany(['soap_type_id' => '03']);
        $demo = $this->makeCompany(['soap_type_id' => '01']);
        $prod = $this->makeCompany(['soap_type_id' => '02']);

        $this->assertStringContainsString('SUNAT · Interno', (string) GreDispatchService::skipReason($internal));
        $this->assertStringContainsString('modo demo', (string) GreDispatchService::skipReason($demo));
        $this->assertNull(GreDispatchService::skipReason($prod));
    }

    public function test_invalid_or_missing_environment_throws_clean_message(): void
    {
        $invalid = $this->makeCompany(['soap_type_id' => '99']);

        foreach ([null, $invalid] as $candidate) {
            try {
                SoapEnvironmentGuard::assertCanEmit($candidate, 'invoice', 'B001');
                $this->fail('Expected invalid environment to throw');
            } catch (Exception $e) {
                $this->assertSame(SoapEnvironmentGuard::INVALID_ENVIRONMENT_MESSAGE, $e->getMessage());
            }
        }
    }

    // -------------------------------------------------------------------------
    // XmlSigned::setCertificateFromFile hardening (regression for errno=21)
    // -------------------------------------------------------------------------

    public function test_xml_signed_rejects_directory_empty_and_missing_paths_without_php_error(): void
    {
        $signer = new XmlSigned();
        $emptyFile = $this->storageRoot.DIRECTORY_SEPARATOR.'empty.pem';
        file_put_contents($emptyFile, "   \n");

        $badPaths = [
            $this->storageRoot, // directory — previously triggered ErrorException errno=21
            $this->storageRoot.DIRECTORY_SEPARATOR,
            '',
            '   ',
            $this->storageRoot.DIRECTORY_SEPARATOR.'does-not-exist.pem',
            $emptyFile,
        ];

        foreach ($badPaths as $path) {
            try {
                $signer->setCertificateFromFile($path);
                $this->fail("Expected InvalidArgumentException for path: {$path}");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('certificado digital', $e->getMessage());
                [$status, $body] = $this->renderThroughHandler($e);
                $this->assertSame(500, $status);
                $this->assertFalse($body['success']);
                $this->assertNotSame(self::GENERIC, $body['message']);
            }
        }
    }

    public function test_xml_signed_signs_real_xml_with_bundled_demo_certificate(): void
    {
        $signer = new XmlSigned();
        $signer->setCertificateFromFile(SoapEnvironmentGuard::demoCertificatePath());

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2" '
            .'xmlns:ext="urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2">'
            .'<ext:UBLExtensions><ext:UBLExtension><ext:ExtensionContent/></ext:UBLExtension></ext:UBLExtensions>'
            .'</Invoice>';

        $signed = $signer->signXml($xml);
        $this->assertStringContainsString('<ds:Signature', $signed);
        $this->assertStringContainsString('<ds:DigestValue>', $signed);
    }

    // -------------------------------------------------------------------------
    // Real Facturalo + Handler integration across 01, 02 (incomplete), 03
    // -------------------------------------------------------------------------

    public function test_real_facturalo_in_internal_mode_blocks_save_and_handler_preserves_message(): void
    {
        $this->db->table('companies')->insert([
            'id' => 1,
            'number' => '20600000001',
            'soap_send_id' => '01',
            'soap_type_id' => '03',
            'soap_username' => null,
            'soap_password' => null,
            'soap_url' => null,
            'certificate' => null,
        ]);

        $facturalo = new Facturalo();

        try {
            $facturalo->save([
                'type' => 'invoice',
                'document_type_id' => '03',
                'series' => 'B001',
                'date_of_issue' => date('Y-m-d'),
                'total' => 524.90,
            ]);
            $this->fail('Expected Facturalo::save() to reject CPE in SUNAT · Interno');
        } catch (Exception $e) {
            $this->assertSame(Exception::class, get_class($e), $e->getMessage());
            [$status, $body] = $this->renderThroughHandler($e);
            $this->assertSame(500, $status);
            $this->assertFalse($body['success']);
            $this->assertSame(SoapEnvironmentGuard::INTERNAL_MODE_MESSAGE, $body['message']);
        }
    }

    public function test_real_facturalo_in_incomplete_production_blocks_save_and_sign_with_clear_message(): void
    {
        $this->db->table('companies')->insert([
            'id' => 1,
            'number' => '20600000001',
            'soap_send_id' => '01',
            'soap_type_id' => '02',
            'soap_username' => null,
            'soap_password' => null,
            'soap_url' => null,
            'certificate' => null,
        ]);

        $facturalo = new Facturalo();

        try {
            $facturalo->save([
                'type' => 'invoice',
                'document_type_id' => '01',
                'series' => 'F001',
                'date_of_issue' => date('Y-m-d'),
                'total' => 100.00,
            ]);
            $this->fail('Expected Facturalo::save() to reject incomplete Production');
        } catch (Exception $e) {
            $this->assertSame(Exception::class, get_class($e), $e->getMessage());
            [$status, $body] = $this->renderThroughHandler($e);
            $this->assertSame(500, $status);
            $this->assertFalse($body['success']);
            $this->assertStringContainsString('Producción', $body['message']);
            $this->assertStringContainsString('usuario SOL', $body['message']);
            $this->assertStringContainsString('certificado digital', $body['message']);
            $this->assertNotSame(self::GENERIC, $body['message']);
        }

        try {
            $facturalo->signXmlUnsigned();
            $this->fail('Expected Facturalo::signXmlUnsigned() to reject incomplete Production');
        } catch (Exception $e) {
            $this->assertSame(Exception::class, get_class($e), $e->getMessage());
            [$status, $body] = $this->renderThroughHandler($e);
            $this->assertFalse($body['success']);
            $this->assertStringContainsString('certificado digital', $body['message']);
            $this->assertNotSame(self::GENERIC, $body['message']);
        }
    }

    public function test_real_facturalo_in_demo_mode_signs_xml_cleanly_without_company_certificate(): void
    {
        $this->db->table('companies')->insert([
            'id' => 1,
            'number' => '20600000001',
            'soap_send_id' => '01',
            'soap_type_id' => '01',
            'soap_username' => null,
            'soap_password' => null,
            'soap_url' => null,
            'certificate' => null,
        ]);

        $facturalo = new Facturalo();
        $doc = new Document();
        $doc->forceFill(['filename' => '20600000001-03-B001-1']);
        $facturalo->setDocument($doc);

        $xmlProp = new \ReflectionProperty(Facturalo::class, 'xmlUnsigned');
        $xmlProp->setAccessible(true);
        $xmlProp->setValue(
            $facturalo,
            '<?xml version="1.0" encoding="UTF-8"?>'
            .'<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2" '
            .'xmlns:ext="urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2">'
            .'<ext:UBLExtensions><ext:UBLExtension><ext:ExtensionContent/></ext:UBLExtension></ext:UBLExtensions>'
            .'</Invoice>'
        );

        $facturalo->signXmlUnsigned();

        $signedProp = new \ReflectionProperty(Facturalo::class, 'xmlSigned');
        $signedProp->setAccessible(true);
        $signedXml = $signedProp->getValue($facturalo);

        $this->assertIsString($signedXml);
        $this->assertStringContainsString('<ds:Signature', $signedXml);
        $this->assertFileExists($this->storageRoot.DIRECTORY_SEPARATOR.'signed'.DIRECTORY_SEPARATOR.'20600000001-03-B001-1.xml');
    }
}
