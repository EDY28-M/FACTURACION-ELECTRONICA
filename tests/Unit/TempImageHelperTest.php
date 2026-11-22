<?php

namespace Tests\Unit;

use App\CoreFacturalo\Helpers\Storage\TempImageHelper;
use Hyn\Tenancy\Contracts\CurrentHostname;
use Illuminate\Auth\GenericUser;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;

class TempImageHelperTest extends TestCase
{
    private $previousContainer;
    private $previousFacadeApplication;
    private $app;
    private $request;
    private $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $this->previousFacadeApplication = Facade::getFacadeApplication();
        $this->app = new Application(dirname(__DIR__, 2));
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);
        $this->app->instance('cache', new Repository(new ArrayStore()));
        $this->app->instance(CurrentHostname::class, (object) ['website_id' => 11]);
        $this->request = Request::create('https://tenant.test/items/upload', 'POST');
        $session = new Store('test', new ArraySessionHandler(120));
        $session->start();
        $this->request->setLaravelSession($session);
        $this->request->setUserResolver(fn () => new GenericUser(['id' => 7]));
        $this->app->instance('request', $this->request);
        Carbon::setTestNow(Carbon::parse('2026-11-16 12:00:00'));
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        Carbon::setTestNow();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacadeApplication);
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    private function image(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'upload_test_');
        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII='));
        $this->files[] = $path;
        return $path;
    }

    private function upload(): array
    {
        $response = TempImageHelper::storeTemp(new UploadedFile($this->image(), 'photo.png', 'image/png', null, true));
        $this->files[] = $response['data']['temp_path'];
        return $response;
    }

    private function assertRejected(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected an unowned or expired upload to be rejected.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }
    }

    public function test_issued_image_can_be_resolved_retried_and_consumed_after_save(): void
    {
        $response = $this->upload();
        $path = $response['data']['temp_path'];
        $this->assertTrue($response['success']);
        $this->assertSame('photo.png', $response['data']['filename']);
        $this->assertStringStartsWith('data:image/png;base64,', $response['data']['temp_image']);
        $resolved = TempImageHelper::resolveValidated($path);
        $this->assertSame(realpath($path), $resolved['path']);
        $this->assertSame('png', $resolved['extension']);
        // Validation is not consumption: a failed database save can be retried.
        $this->assertSame($resolved, TempImageHelper::resolveValidated($path));
        TempImageHelper::deleteOwned($resolved['path']);
        $this->assertFileDoesNotExist($path);
        // Main and additional image lists can refer to the same issued upload.
        TempImageHelper::deleteOwned($resolved['path']);
        file_put_contents($path, 'replacement');
        $this->assertRejected(fn () => TempImageHelper::resolveValidated($path));
        TempImageHelper::deleteOwned($path);
        $this->assertSame('replacement', file_get_contents($path));
    }

    public function test_never_issued_image_is_not_read_or_deleted(): void
    {
        $path = $this->image();
        $contents = file_get_contents($path);
        $this->assertRejected(fn () => TempImageHelper::resolveValidated($path));
        TempImageHelper::deleteOwned($path);
        $this->assertSame($contents, file_get_contents($path));
    }

    public function test_other_tenant_user_and_session_cannot_resolve_or_delete_an_upload(): void
    {
        $path = $this->upload()['data']['temp_path'];
        $contents = file_get_contents($path);
        $sessionId = $this->request->session()->getId();
        foreach (['tenant', 'user', 'session'] as $foreignContext) {
            $this->app->instance(CurrentHostname::class, (object) ['website_id' => $foreignContext === 'tenant' ? 12 : 11]);
            $this->request->setUserResolver(fn () => new GenericUser(['id' => $foreignContext === 'user' ? 8 : 7]));
            $this->request->session()->setId($foreignContext === 'session' ? str_repeat('a', 40) : $sessionId);
            $this->assertRejected(fn () => TempImageHelper::resolveValidated($path));
            TempImageHelper::deleteOwned($path);
            $this->assertSame($contents, file_get_contents($path));
        }
        $this->request->session()->setId($sessionId);
        $this->assertSame(realpath($path), TempImageHelper::resolveValidated($path)['path']);
    }

    public function test_expired_upload_is_rejected_and_left_untouched(): void
    {
        $path = $this->upload()['data']['temp_path'];
        Carbon::setTestNow(Carbon::now()->addHours(2)->addSecond());
        $this->assertRejected(fn () => TempImageHelper::resolveValidated($path));
        TempImageHelper::deleteOwned($path);
        $this->assertFileExists($path);
    }

    public function test_missing_session_user_or_tenant_fails_closed(): void
    {
        foreach (['session', 'user', 'tenant'] as $missingContext) {
            $originalRequest = $this->request;
            if ($missingContext === 'session') {
                $this->app->instance('request', Request::create('https://tenant.test/api/items', 'POST'));
            } elseif ($missingContext === 'user') {
                $this->request->setUserResolver(fn () => null);
            } else {
                $this->app->forgetInstance(CurrentHostname::class);
                $this->app->singleton(CurrentHostname::class, fn () => null);
            }
            $this->assertRejected(fn () => $this->upload());
            $this->app->instance('request', $originalRequest);
            $this->request->setUserResolver(fn () => new GenericUser(['id' => 7]));
        }
    }

    public function test_separate_uploads_remain_available_without_overwriting_registration(): void
    {
        $first = $this->upload()['data']['temp_path'];
        $second = $this->upload()['data']['temp_path'];
        TempImageHelper::deleteOwned(TempImageHelper::resolveValidated($first)['path']);
        $this->assertSame(realpath($second), TempImageHelper::resolveValidated($second)['path']);
    }

    public function test_issued_path_still_requires_a_real_image(): void
    {
        $path = $this->upload()['data']['temp_path'];
        file_put_contents($path, '<?php echo "not an image";');
        $this->assertRejected(fn () => TempImageHelper::resolveValidated($path));
        $this->assertFileExists($path);
    }
}
