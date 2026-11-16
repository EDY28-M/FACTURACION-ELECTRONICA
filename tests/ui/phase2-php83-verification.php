<?php

require __DIR__ . '/../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

function assertTrue($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

// 1. Verify PHP 8.3 & Laravel 10
assertTrue(version_compare(PHP_VERSION, '8.3.0', '>='), 'PHP version must be >= 8.3.0, got ' . PHP_VERSION);
assertTrue(version_compare(app()->version(), '10.0.0', '>='), 'Laravel version must be >= 10.0.0, got ' . app()->version());
echo "[OK] Runtime: PHP " . PHP_VERSION . " / Laravel " . app()->version() . "\n";

// 2. Verify Hyn\Tenancy PSR-4 Bridge
$env = app(\Hyn\Tenancy\Environment::class);
assertTrue($env instanceof \Hyn\Tenancy\Environment, 'Hyn\Tenancy\Environment must resolve');
assertTrue($env->installed() === true, 'Tenancy environment must detect installed system tables');

$sysModel = new \App\Models\System\Client();
assertTrue($sysModel->getConnectionName() === 'system', 'System model must use system connection');

$tenantModel = new \App\Models\Tenant\Document();
assertTrue($tenantModel->getConnectionName() === 'tenant', 'Tenant model must use tenant connection');

$websiteRepo = app(\Hyn\Tenancy\Contracts\Repositories\WebsiteRepository::class);
$hostnameRepo = app(\Hyn\Tenancy\Contracts\Repositories\HostnameRepository::class);
assertTrue($websiteRepo instanceof \Hyn\Tenancy\Repositories\WebsiteRepository, 'WebsiteRepository must resolve');
assertTrue($hostnameRepo instanceof \Hyn\Tenancy\Repositories\HostnameRepository, 'HostnameRepository must resolve');

// Verify $env->tenant(null) resets tenant connection cleanly
$dummyWebsite = new \Hyn\Tenancy\Models\Website(['uuid' => 'tenancy_test_reset']);
$env->tenant($dummyWebsite);
assertTrue($env->tenant() === $dummyWebsite, 'Environment::tenant($website) must set active tenant');
assertTrue((config('database.connections.tenant.database') ?? null) === 'tenancy_test_reset', 'Tenant DB config must reflect active website');
$env->tenant(null);
assertTrue($env->tenant() === null, 'Environment::tenant(null) must clear active tenant');
assertTrue(empty(config('database.connections.tenant')), 'Environment::tenant(null) must purge tenant DB config');
echo "[OK] Hyn\\Tenancy PSR-4 drop-in bridge verified (including tenant(null) reset)\n";

// 3. Verify OpenSSL 3 + Legacy PKCS#12 (.pfx) Certificate Generation & Parsing + XML Digital Signature
$privKey = openssl_pkey_new([
    'private_key_bits' => 2048,
    'private_key_type' => OPENSSL_KEYTYPE_RSA,
]);
$csr = openssl_csr_new(['commonName' => 'facturadorpro4.sunat.test'], $privKey);
$x509 = openssl_csr_sign($csr, null, $privKey, 365);
$pfxOut = '';
$exported = openssl_pkcs12_export($x509, $pfxOut, $privKey, 'secret123');
assertTrue($exported === true && strlen($pfxOut) > 0, 'Must export PKCS#12 bundle');

$pem = \App\CoreFacturalo\Helpers\Certificate\GenerateCertificate::typePEM($pfxOut, 'secret123');
assertTrue(strpos($pem, '-----BEGIN CERTIFICATE-----') !== false && strpos($pem, '-----BEGIN PRIVATE KEY-----') !== false, 'GenerateCertificate::typePEM must extract cert and private key');

// Also verify the OpenSSL 3 CLI -legacy fallback path (env:OPENSSL_PFX_PASS + try/finally cleanup)
$x509Obj = new \App\CoreFacturalo\WS\Signed\Certificate\X509Certificate($pfxOut, 'secret123');
$reflMethod = new \ReflectionMethod($x509Obj, 'parsePfxViaOpenSslCli');
$reflMethod->setAccessible(true);
$cliCerts = $reflMethod->invoke($x509Obj, $pfxOut, 'secret123');
assertTrue(is_array($cliCerts) && !empty($cliCerts['cert']) && !empty($cliCerts['pkey']), 'OpenSSL CLI -legacy fallback must parse PFX using env:OPENSSL_PFX_PASS');

$signer = new \App\CoreFacturalo\WS\Signed\XmlSigned();
$signer->setCertificate($pem);
$sampleXml = '<?xml version="1.0" encoding="UTF-8"?><Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2" xmlns:ext="urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2"><ext:UBLExtensions><ext:UBLExtension><ext:ExtensionContent/></ext:UBLExtension></ext:UBLExtensions><ID>F001-1</ID></Invoice>';
$signedXml = $signer->signXml($sampleXml);
assertTrue(strpos($signedXml, 'SignatureValue') !== false && strpos($signedXml, 'DigestValue') !== false, 'XmlSigned must sign UBL XML on PHP 8.3');

$hashHelper = new \App\CoreFacturalo\Helpers\Xml\XmlHash();
$digest = $hashHelper->getHashSign($signedXml);
assertTrue(!empty($digest), 'XmlHash must extract DigestValue from signed UBL XML');
echo "[OK] CoreFacturalo PKCS#12 + XML DSig signing verified (Digest: {$digest})\n";

// 4. Verify Mpdf 8.2 PDF & QR Generation on PHP 8.3
$mpdf = new \Mpdf\Mpdf();
$mpdf->WriteHTML('<h1>Factura Electrónica F001-1</h1>');
$pdfBytes = $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
assertTrue(strpos($pdfBytes, '%PDF-') === 0, 'Mpdf must generate valid PDF bytes on PHP 8.3');
echo "[OK] Mpdf PDF generation verified (" . strlen($pdfBytes) . " bytes)\n";

// 5. Verify System Admin HTTP Routes & Controllers as Authenticated Admin
$admin = \App\Models\System\User::first();
assertTrue($admin !== null, 'System admin user must exist');
\Illuminate\Support\Facades\Auth::guard('admin')->login($admin);

$routesToTest = [
    ['GET', '/dashboard', 200],
    ['GET', '/clients', 200],
    ['GET', '/clients/records', 200],
    ['GET', '/clients/tables', 200],
    ['GET', '/clients/charts', 200],
    ['GET', '/plans', 200],
    ['GET', '/plans/records', 200],
    ['GET', '/accounting', 200],
    ['GET', '/backup', 200],
    ['GET', '/logs', 200],
    ['GET', '/auto-update', 200],
    ['GET', '/auto-update/version', 200],
    ['GET', '/auto-update/branch', 200],
    ['GET', '/auto-update/changelog', 200],
    ['GET', '/configurations', 200],
    ['GET', '/users/create', 200],
    ['GET', '/users/record', 200],
    ['GET', '/certificates/record', 200],
    ['GET', '/companies/record', 200],
    ['GET', '/login', 302],
    ['GET', '/__nonexistent_route_404_check__', 404],
];

foreach ($routesToTest as [$method, $uri, $expectedStatus]) {
    $request = \Illuminate\Http\Request::create($uri, $method, [], [], [], [
        'HTTP_HOST' => 'localhost',
        'HTTP_ACCEPT' => 'text/html,application/xhtml+xml,application/json',
    ]);
    $request->setLaravelSession($app['session']->driver());
    $response = $kernel->handle($request);
    $status = $response->getStatusCode();
    if ($status !== $expectedStatus) {
        fwrite(STDERR, "FAIL: {$method} {$uri} returned {$status} (expected {$expectedStatus})\n" . substr($response->getContent(), 0, 1000) . "\n");
        exit(1);
    }
    echo "[OK] {$method} {$uri} -> {$status}\n";
}

// Verify Handler::render() with a generic RuntimeException when $request->route() is null
$nullRouteReq = \Illuminate\Http\Request::create('/__unmatched_path__', 'GET', [], [], [], [
    'HTTP_ACCEPT' => 'text/html',
]);
$handler = $app->make(\Illuminate\Contracts\Debug\ExceptionHandler::class);
$errResp = $handler->render($nullRouteReq, new \RuntimeException('Null route test'));
assertTrue($errResp->getStatusCode() === 500, 'Handler::render on null-route HTML request must return 500 without throwing');
echo "[OK] Handler::render null-route guard verified\n";

// 6. Verify GrahamCampbell\Markdown v15 (CommonMark 2.x) config & rendering
$mdHtml = (string) \GrahamCampbell\Markdown\Facades\Markdown::convertToHtml("# Changelog\n\n| Version | Status |\n| --- | --- |\n| 10.x | OK |");
assertTrue(strpos($mdHtml, '<h1>Changelog</h1>') !== false && strpos($mdHtml, '<table>') !== false, 'Markdown::convertToHtml must render CommonMark + Tables on Laravel 10');
echo "[OK] GrahamCampbell\\Markdown v15 rendering verified\n";

// 7. Verify Seeder aliases & Connection::seed() on Laravel 10
assertTrue(class_exists(\Database\Seeders\DatabaseSeeder::class), 'Database\\Seeders\\DatabaseSeeder alias must exist');
assertTrue(class_exists(\Database\Seeders\TenancyDatabaseSeeder::class), 'Database\\Seeders\\TenancyDatabaseSeeder alias must exist');
$seedTestDb = 'audit_seed_verify_db';
\Illuminate\Support\Facades\DB::connection('system')->statement("DROP DATABASE IF EXISTS `{$seedTestDb}`");
\Illuminate\Support\Facades\DB::connection('system')->statement("CREATE DATABASE `{$seedTestDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
\Illuminate\Support\Facades\DB::connection('system')->statement("CREATE TABLE `{$seedTestDb}`.`format_templates` (`id` int unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, `formats` varchar(255) NOT NULL)");
$seedWebsite = new \Hyn\Tenancy\Models\Website(['uuid' => $seedTestDb]);
$tenancyConn = app(\Hyn\Tenancy\Database\Connection::class);
try {
    $seedOk = $tenancyConn->seed($seedWebsite);
    $seededCount = \Illuminate\Support\Facades\DB::connection('tenant')->table('format_templates')->count();
    assertTrue($seedOk === true && $seededCount === 14, "Connection::seed() must seed 14 format_templates rows, got {$seededCount}");
} finally {
    $env->tenant(null);
    \Illuminate\Support\Facades\DB::connection('system')->statement("DROP DATABASE IF EXISTS `{$seedTestDb}`");
}
echo "[OK] Laravel 10 Database\\Seeders class aliases & Connection::seed() verified (14 rows)\n";

// 8. Verify implicit DDL commit transaction recovery on system connection
$sysConn = \Illuminate\Support\Facades\DB::connection('system');
$sysConn->beginTransaction();
$sysConn->statement('DROP DATABASE IF EXISTS `audit_ddl_implicit_commit_test`');
assertTrue($sysConn->getPdo()->inTransaction() === false, 'DDL statement implicitly commits PDO transaction');
assertTrue($sysConn->transactionLevel() === 1, 'Laravel transactionLevel remains 1 after implicit DDL commit');
$sysConn->rollBack(0);
assertTrue($sysConn->transactionLevel() === 0, 'rollBack(0) resets transactionLevel cleanly without throwing PDOException');
echo "[OK] Implicit DDL commit transaction recovery verified\n";

// 9. Verify Phase 3 Tenant Persistent Shell & Tenant ERP Routes on net.localhost
assertTrue(file_exists(public_path('js/tenant-shell.js')), 'public/js/tenant-shell.js must exist');
assertTrue(file_exists(public_path('css/tenant-theme.css')), 'public/css/tenant-theme.css must exist');

$netHostname = \Hyn\Tenancy\Models\Hostname::where('fqdn', 'net.localhost')->with('website')->first();
assertTrue($netHostname !== null && $netHostname->website !== null, 'Tenant hostname net.localhost must exist');
$env->hostname($netHostname);

app('router')->setRoutes(new \Illuminate\Routing\RouteCollection());
\Illuminate\Support\Facades\Route::middleware('web')
    ->namespace('App\Http\Controllers')
    ->group(base_path('routes/web.php'));
foreach (glob(base_path('modules/*/Routes/web.php')) as $moduleRouteFile) {
    $moduleName = basename(dirname(dirname($moduleRouteFile)));
    \Illuminate\Support\Facades\Route::middleware('web')
        ->namespace("Modules\\{$moduleName}\\Http\\Controllers")
        ->group($moduleRouteFile);
}
app('router')->getRoutes()->refreshNameLookups();
app('router')->getRoutes()->refreshActionLookups();

$tenantUser = \App\Models\Tenant\User::first();
assertTrue($tenantUser !== null, 'Tenant user must exist in net.localhost');
\Illuminate\Support\Facades\Auth::shouldUse('web');
\Illuminate\Support\Facades\Auth::guard('web')->login($tenantUser);

$tenantRoutesToTest = [
    ['GET', '/dashboard', 200],
    ['GET', '/documents', 200],
    ['GET', '/documents/create', 200],
    ['GET', '/quotations', 200],
    ['GET', '/quotations/records', 200],
    ['GET', '/sale-notes', 200],
    // 302 redirects to open a cash register; 200 when one is already open (e.g. seeded demo data)
    ['GET', '/pos', [200, 302]],
    ['GET', '/cash', 200],
    ['GET', '/items', 200],
    ['GET', '/persons/customers', 200],
    ['GET', '/purchases', 200],
    ['GET', '/purchases/create', 200],
    ['GET', '/inventory', 200],
    ['GET', '/users', 200],
    ['GET', '/establishments', 200],
    ['GET', '/list-reports', 200],
    ['GET', '/list-settings', 200],
    ['GET', '/configurations/create', 200],
    ['GET', '/advanced', 200],
];

foreach ($tenantRoutesToTest as [$method, $uri, $expectedStatus]) {
    $tReq = \Illuminate\Http\Request::create('http://net.localhost' . $uri, $method, [], [], [], [
        'HTTP_HOST' => 'net.localhost',
        'HTTP_ACCEPT' => 'text/html,application/xhtml+xml,application/json',
    ]);
    $tReq->setLaravelSession($app['session']->driver());
    $tResp = $kernel->handle($tReq);
    $tStatus = $tResp->getStatusCode();
    if (! in_array($tStatus, (array) $expectedStatus, true)) {
        $expectedText = implode(' or ', (array) $expectedStatus);
        fwrite(STDERR, "FAIL (Tenant net.localhost): {$method} {$uri} returned {$tStatus} (expected {$expectedText})\n" . substr($tResp->getContent(), 0, 1000) . "\n");
        exit(1);
    }
    if ($uri === '/dashboard') {
        $dashHtml = $tResp->getContent();
        assertTrue(strpos($dashHtml, 'id="main-wrapper"') !== false, 'Tenant /dashboard must render #main-wrapper');
        assertTrue(strpos($dashHtml, 'css/tenant-theme.css') !== false, 'Tenant /dashboard must include css/tenant-theme.css');
        assertTrue(strpos($dashHtml, 'js/tenant-shell.js') !== false, 'Tenant /dashboard must include js/tenant-shell.js');
        assertTrue(strpos($dashHtml, 'data-shell-ssr') !== false, 'Tenant layout must mark server-rendered head styles for shell head diffing');
        assertTrue(strpos($dashHtml, 'data-shell-notifications') !== false, 'Tenant /dashboard must render the stable notifications wrapper');
        assertTrue(strpos($dashHtml, 'href=" # "') === false, 'Tenant /dashboard must not render href=" # "');
    }
    if ($uri === '/documents/create') {
        $createHtml = $tResp->getContent();
        assertTrue(strpos($createHtml, 'newinvoice') !== false, 'Tenant /documents/create must include newinvoice class on html');
    }
    echo "[OK] Tenant net.localhost {$method} {$uri} -> {$tStatus}\n";
}

// Verify DELETE purchases/destroy_purchase_item/{purchase_item} route controller resolution
$matchedDestroyRoute = app('router')->getRoutes()->match(
    \Illuminate\Http\Request::create('http://net.localhost/purchases/destroy_purchase_item/1', 'DELETE', [], [], [], ['HTTP_HOST' => 'net.localhost'])
);
$destroyController = $matchedDestroyRoute->getActionName();
assertTrue(
    strpos($destroyController, 'App\\Http\\Controllers\\Tenant\\PurchaseController@destroy_purchase_item') !== false,
    "Destroy purchase item route must resolve to Tenant\\PurchaseController, got {$destroyController}"
);
assertTrue(class_exists(\App\Http\Controllers\Tenant\PurchaseController::class), 'Tenant\\PurchaseController must exist');
echo "[OK] Tenant PurchaseController@destroy_purchase_item route action verified\n";

// Phase 3 review regressions: QuotationController PHP 8.3 safety and optional {format?}
$quotationRef = new \ReflectionClass(\App\Http\Controllers\Tenant\QuotationController::class);
foreach (['download', 'toPrint'] as $quotationMethod) {
    $formatParam = $quotationRef->getMethod($quotationMethod)->getParameters()[1] ?? null;
    assertTrue(
        $formatParam !== null && $formatParam->isDefaultValueAvailable() && $formatParam->getDefaultValue() === 'a4',
        "QuotationController@{$quotationMethod} must default \$format to 'a4' for optional {format?} routes"
    );
}
$quotationSource = file_get_contents(base_path('app/Http/Controllers/Tenant/QuotationController.php'));
assertTrue(strpos($quotationSource, '$form = $request->form ? json_decode($request->form) : null;') !== false, 'QuotationController@records must not pass null to json_decode()');
assertTrue(strpos($quotationSource, 'pdfFooter($base_template,$this->quotation)') === false && strpos($quotationSource, 'pdfFooterLegend($base_template, $this->quotation)') === false, 'QuotationController@createPdf must pass $document to footer templates');
echo "[OK] QuotationController PHP 8.3 json_decode guard, format defaults and pdf footer document verified\n";

// Phase 3 review regressions: tenant layout markers for shell head/notification sync
$headerBlade = file_get_contents(resource_path('views/tenant/layouts/partials/header.blade.php'));
assertTrue(strpos($headerBlade, '@else # @endif') === false, 'Tenant header must not render whitespace-padded href=" # "');
assertTrue(strpos($headerBlade, 'data-shell-notifications') !== false, 'Tenant header must expose a stable notifications wrapper');
$configFormBlade = file_get_contents(resource_path('views/tenant/configurations/form.blade.php'));
assertTrue(strpos($configFormBlade, 'optional(auth()->user())->type') !== false, 'Configurations form must guard auth()->user() with optional()');
echo "[OK] Tenant header/configuration blade guards verified\n";

echo "ALL PHASE 2 & PHASE 3 PHP 8.3 + LARAVEL 10 VERIFICATION CHECKS PASSED.\n";
