<?php

namespace Tests\Unit;

use App\CoreFacturalo\Helpers\Storage\TempImageHelper;
use Hyn\Tenancy\Contracts\CurrentHostname;
use Illuminate\Auth\GenericUser;
use Illuminate\Cache\CacheManager;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use League\Flysystem\Filesystem as Flysystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Modules\Finance\Helpers\PaymentFileHelper;
use Modules\Finance\Http\Controllers\PaymentFileController;
use Modules\Finance\Models\PaymentFile;
use Modules\Finance\Traits\FilePaymentTrait;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PaymentAttachmentRecord extends Model
{
    protected $connection = 'tenant';
    protected $table = 'attachment_test_records';
    protected $guarded = [];
    public $timestamps = false;
    public $failRelation = false;

    public function payment_file()
    {
        if ($this->failRelation) {
            throw new \RuntimeException('Injected relation failure');
        }
        return $this->morphOne(PaymentFile::class, 'payment');
    }
}

class PaymentFileTest extends TestCase
{
    private $previousContainer;
    private $previousFacadeApplication;
    private $previousResolver;
    private $app;
    private $request;
    private $connection;
    private $disk;
    private $filesystem;
    private $root;
    private $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $this->previousFacadeApplication = Facade::getFacadeApplication();
        $this->previousResolver = Model::getConnectionResolver();
        $this->app = new Application(dirname(__DIR__, 2));
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);
        $this->app->instance('config', new Repository(['cache' => ['default' => 'array', 'stores' => ['array' => ['driver' => 'array']]]]));
        $this->app->instance('cache', new CacheManager($this->app));
        $this->filesystem = new class extends Filesystem {
            public $failRead = false;
            public function get($path, $lock = false) { return $this->failRead ? false : parent::get($path, $lock); }
        };
        $this->app->instance('files', $this->filesystem);
        $this->app->instance('validator', new Factory(new Translator(new ArrayLoader(), 'en'), $this->app));
        if (!class_exists('Validator')) {
            class_alias(\Illuminate\Support\Facades\Validator::class, 'Validator');
        }
        $this->app->instance(CurrentHostname::class, (object) ['website_id' => 11]);
        $this->request = Request::create('https://tenant.test/finances/payment-file/upload', 'POST');
        $session = new Store('test', new ArraySessionHandler(120));
        $session->start();
        $this->request->setLaravelSession($session);
        $this->request->setUserResolver(fn () => new GenericUser(['id' => 7]));
        $this->app->instance('request', $this->request);
        Carbon::setTestNow(Carbon::parse('2026-11-16 12:00:00'));

        $this->root = sys_get_temp_dir() . '/payment-test-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
        $adapter = new LocalFilesystemAdapter($this->root);
        $this->disk = new class(new Flysystem($adapter), $adapter) extends FilesystemAdapter {
            public $failWrite = false;
            public $beforeWrite;
            public function put($path, $contents, $options = [])
            {
                if ($this->beforeWrite) { ($this->beforeWrite)($path, $contents); }
                return $this->failWrite ? false : parent::put($path, $contents, $options);
            }
        };
        $this->app->instance('filesystem', new class($this->disk) {
            private $disk;
            public function __construct($disk) { $this->disk = $disk; }
            public function disk($name) { return $this->disk; }
        });
        $this->connection = new SQLiteConnection(new \PDO('sqlite::memory:'), '', '', ['name' => 'tenant']);
        $this->connection->setTransactionManager(new DatabaseTransactionsManager());
        $this->connection->statement('create table attachment_test_records (id integer primary key autoincrement)');
        $this->connection->statement('create table payment_files (id integer primary key autoincrement, filename text, payment_type text, payment_id integer)');
        Model::setConnectionResolver(new ConnectionResolver(['tenant' => $this->connection]));
    }

    protected function tearDown(): void
    {
        while ($this->connection->transactionLevel() > 0) { $this->connection->rollBack(); }
        foreach ($this->files as $path) { if (is_file($path)) { unlink($path); } }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) { $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname()); }
        rmdir($this->root);
        if ($this->previousResolver) { Model::setConnectionResolver($this->previousResolver); }
        else { Model::unsetConnectionResolver(); }
        Carbon::setTestNow();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacadeApplication);
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    private function file(string $format, ?string $name = null): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'payment-fixture-');
        $this->files[] = $path;
        if ($format === 'xlsx') {
            $workbook = new Spreadsheet();
            $workbook->getActiveSheet()->setCellValue('A1', 'Payment receipt')->setCellValue('B1', 12.50);
            (new Xlsx($workbook))->save($path);
            $workbook->disconnectWorksheets();
        } elseif (in_array($format, ['jpg', 'jpeg'], true)) {
            $image = imagecreatetruecolor(2, 2);
            imagejpeg($image, $path);
            imagedestroy($image);
        } else {
            $contents = [
                'png' => base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII='),
                'gif' => base64_decode('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw=='),
                'svg' => '<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"><rect width="1" height="1"/></svg>',
                'pdf' => "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n",
            ];
            file_put_contents($path, $contents[$format]);
        }
        return new UploadedFile($path, $name ?? 'receipt.' . $format, null, null, true);
    }

    private function upload(string $format = 'pdf', ?string $name = null): array
    {
        $request = Request::create('https://tenant.test/finances/payment-file/upload', 'POST', ['index' => '3']);
        $request->setLaravelSession($this->request->session());
        $request->setUserResolver($this->request->getUserResolver());
        $this->request = $request;
        $this->app->instance('request', $request);
        $this->request->files->set('file', $this->file($format, $name));
        $response = (new PaymentFileController())->uploadAttached($this->request);
        $this->assertTrue($response['success'], $response['message'] ?? $format);
        $this->files[] = $response['data']['temp_path'];
        return $response;
    }

    private function save(string $path, string $filename = 'attacker.php', ?PaymentAttachmentRecord $record = null): PaymentAttachmentRecord
    {
        $record = $record ?? PaymentAttachmentRecord::create();
        $request = Request::create('/document-payments', 'POST', ['temp_path' => $path, 'filename' => $filename]);
        (new class { use FilePaymentTrait; })->saveFiles($record, $request, 'documents');
        return $record;
    }

    private function rejected(callable $operation): void
    {
        $caught = null;
        try { $operation(); } catch (HttpException $exception) { $caught = $exception; }
        $this->assertInstanceOf(HttpException::class, $caught);
        $this->assertSame(422, $caught->getStatusCode());
    }

    public function test_existing_formats_and_upload_payload_are_preserved_without_type(): void
    {
        foreach (['jpg', 'jpeg', 'png', 'gif', 'svg', 'pdf', 'xlsx'] as $format) {
            $response = $this->upload($format);
            $this->assertSame(['success', 'data'], array_keys($response));
            $this->assertSame(['filename', 'temp_path', 'index'], array_keys($response['data']));
            $this->assertSame('receipt.' . $format, $response['data']['filename']);
            $this->assertSame(3, $response['data']['index']);
            $resolved = PaymentFileHelper::resolveValidated($response['data']['temp_path']);
            $this->assertSame($format === 'jpeg' ? 'jpg' : $format, $resolved['extension']);
            $record = $this->save($resolved['path'], '../../malicious.php.extra');
            $attachment = $record->payment_file()->firstOrFail();
            $this->assertSame($resolved['extension'], pathinfo($attachment->filename, PATHINFO_EXTENSION));
            $this->assertMatchesRegularExpression('/^payment-[a-f0-9-]+\.(jpg|png|gif|svg|pdf|xlsx)$/', $attachment->filename);
            $this->assertTrue($this->disk->exists('payment_files/documents/' . $attachment->filename));
            $this->assertFileDoesNotExist($resolved['path']);
        }
    }

    public function test_unissued_foreign_and_expired_paths_remain_untouched(): void
    {
        $unissued = $this->file('pdf')->getRealPath();
        $this->rejected(fn () => $this->save($unissued));
        $this->assertFileExists($unissued);
        $path = $this->upload()['data']['temp_path'];
        $contents = file_get_contents($path);
        $sessionId = $this->request->session()->getId();
        foreach (['tenant', 'user', 'session'] as $foreign) {
            $this->app->instance(CurrentHostname::class, (object) ['website_id' => $foreign === 'tenant' ? 12 : 11]);
            $this->request->setUserResolver(fn () => new GenericUser(['id' => $foreign === 'user' ? 8 : 7]));
            $this->request->session()->setId($foreign === 'session' ? str_repeat('a', 40) : $sessionId);
            $this->rejected(fn () => $this->save($path));
            $this->assertSame($contents, file_get_contents($path));
        }
        $this->request->session()->setId($sessionId);
        Carbon::setTestNow(Carbon::now()->addHours(2)->addSecond());
        $this->rejected(fn () => $this->save($path));
        $this->assertSame($contents, file_get_contents($path));
        $this->assertSame(0, PaymentFile::count());
        $this->assertSame([], $this->disk->allFiles());
    }

    public function test_image_uploads_and_wrong_purpose_cannot_be_used_as_payment_attachments(): void
    {
        $image = TempImageHelper::storeTemp($this->file('png'))['data']['temp_path'];
        $this->files[] = $image;
        $this->rejected(fn () => $this->save($image));
        $this->assertSame('png', TempImageHelper::resolveValidated($image)['extension']);
        $path = $this->upload()['data']['temp_path'];
        $key = 'temp-payment-file:' . hash('sha256', realpath($path));
        $registration = Cache::get($key);
        $registration['purpose'] = 'other';
        Cache::put($key, $registration, 7200);
        $this->rejected(fn () => $this->save($path));
        $this->assertFileExists($path);
    }

    public function test_missing_session_upload_is_rejected_and_original_file_is_untouched(): void
    {
        $file = $this->file('pdf');
        $contents = file_get_contents($file->getRealPath());
        $request = Request::create('https://tenant.test/api/payment-file', 'POST');
        $request->setUserResolver(fn () => new GenericUser(['id' => 7]));
        $this->app->instance('request', $request);
        $this->rejected(fn () => PaymentFileHelper::storeTemp($file, 3));
        $this->assertSame($contents, file_get_contents($file->getRealPath()));
    }

    public function test_successful_cleanup_consumes_registration_without_changing_legacy_image_registry(): void
    {
        $path = $this->upload()['data']['temp_path'];
        $contents = file_get_contents($path);
        $this->save($path);
        file_put_contents($path, $contents);
        $this->rejected(fn () => PaymentFileHelper::resolveValidated($path));
        $image = $this->file('png')->getRealPath();
        Cache::put('temp-image:' . hash('sha256', $image), [
            'tenant' => '11', 'user' => '7', 'session' => $this->request->session()->getId(),
        ], 7200);
        $this->assertSame('png', TempImageHelper::resolveValidated($image)['extension']);
    }

    public function test_arbitrary_zip_and_workbookless_zip_are_not_xlsx(): void
    {
        foreach ([false, true] as $withRelationship) {
            $path = tempnam(sys_get_temp_dir(), 'not-xlsx-');
            $this->files[] = $path;
            $zip = new \ZipArchive();
            $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
            $zip->addFromString('random.txt', 'not a workbook');
            if ($withRelationship) {
                $zip->addFromString('_rels/.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
            }
            $zip->close();
            $this->rejected(fn () => PaymentFileHelper::storeTemp(new UploadedFile($path, 'fake.xlsx', null, null, true), 3));
            $this->assertFileExists($path);
        }
    }

    public function test_cleanup_waits_for_outermost_commit_and_rollback_keeps_retryable_upload(): void
    {
        $path = $this->upload()['data']['temp_path'];
        $this->connection->beginTransaction();
        $this->connection->beginTransaction();
        $this->save($path);
        $this->connection->commit();
        $this->assertFileExists($path);
        $this->assertSame('pdf', PaymentFileHelper::resolveValidated($path)['extension']);
        $this->connection->rollBack();
        $this->assertSame(0, PaymentFile::count());
        $this->assertFileExists($path);
        $this->connection->transaction(function () use ($path) { $this->save($path); $this->assertFileExists($path); });
        $this->assertSame(1, PaymentFile::count());
        $this->assertFileDoesNotExist($path);
    }

    public function test_failed_storage_or_relation_retains_registration_and_temporary_file(): void
    {
        foreach (['storage', 'relation', 'read'] as $failure) {
            $path = $this->upload()['data']['temp_path'];
            $record = PaymentAttachmentRecord::create();
            $this->disk->failWrite = $failure === 'storage';
            $this->filesystem->failRead = $failure === 'read';
            $record->failRelation = $failure === 'relation';
            $caught = null;
            try { $this->connection->transaction(fn () => $this->save($path, 'file.pdf', $record)); }
            catch (\RuntimeException $exception) { $caught = $exception; }
            $this->assertInstanceOf(\RuntimeException::class, $caught);
            $this->assertSame(0, PaymentFile::count());
            $this->assertFileExists($path);
            $this->assertSame('pdf', PaymentFileHelper::resolveValidated($path)['extension']);
            $this->disk->failWrite = false;
            $this->filesystem->failRead = false;
        }
    }

    public function test_storage_succeeds_before_relation_is_created(): void
    {
        $path = $this->upload()['data']['temp_path'];
        $this->disk->beforeWrite = function () { $this->assertSame(0, PaymentFile::count()); };
        $this->save($path);
        $this->assertSame(1, PaymentFile::count());
    }

    public function test_cleanup_captures_validated_owner_instead_of_rechecking_changed_request_context(): void
    {
        $path = $this->upload()['data']['temp_path'];
        $this->connection->beginTransaction();
        $this->save($path);
        $this->app->instance(CurrentHostname::class, (object) ['website_id' => 999]);
        $this->request->setUserResolver(fn () => null);
        $this->request->session()->invalidate();
        $this->connection->commit();
        $this->assertFileDoesNotExist($path);
        $this->assertSame(1, PaymentFile::count());
    }

    public function test_postcommit_cleanup_failure_cannot_report_a_committed_payment_as_failed(): void
    {
        $path = $this->upload()['data']['temp_path'];
        $this->connection->beginTransaction();
        $this->save($path);
        $store = Cache::store();
        $failingStore = new class extends \Illuminate\Cache\ArrayStore {
            public function get($key) { throw new \RuntimeException('Injected cache outage'); }
        };
        $store->setStore($failingStore);
        $this->connection->commit();
        $this->assertSame(1, PaymentFile::count());
        $this->assertFileExists($path);
    }

    public function test_svg_download_is_forced_attachment_with_nosniff(): void
    {
        $record = $this->save($this->upload('svg')['data']['temp_path']);
        $filename = $record->payment_file()->firstOrFail()->filename;
        $response = (new PaymentFileController())->download($filename, 'documents');
        $this->assertStringStartsWith('attachment;', $response->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('application/octet-stream', $response->headers->get('Content-Type'));
    }
}
