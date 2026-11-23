<?php

namespace Tests\Unit;

use App\Http\Controllers\Tenant\TagController;
use App\Http\Middleware\RequireStorefrontModule;
use App\Http\Requests\Tenant\ConfigurationEcommerceRequest;
use App\Http\Requests\Tenant\PromotionRequest;
use App\Http\Requests\Tenant\TagRequest;
use App\Models\Tenant\ConfigurationEcommerce;
use App\Support\Access\TenantPlan;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory as ValidatorFactory;
use Illuminate\Validation\ValidationException;
use Modules\Ecommerce\Http\Controllers\ConfigurationController;
use Modules\Ecommerce\Http\Controllers\EcommerceController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Admin/storefront endpoint hardening (QA-02, QA-10, QA-12, QA-16, QA-18, QA-20).
 */
class EcommerceAdminEndpointsTest extends TestCase
{
    private $previousContainer;
    private $previousFacade;
    private $previousResolver;
    private $previousDispatcher;
    private $previousScopes;
    private $db;
    private $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $this->previousFacade = Facade::getFacadeApplication();
        $this->previousResolver = Model::getConnectionResolver();
        $this->previousDispatcher = Model::getEventDispatcher();
        $scopes = new \ReflectionProperty(Model::class, 'globalScopes');
        $scopes->setAccessible(true);
        $this->previousScopes = $scopes->getValue();

        $app = new Container();
        Container::setInstance($app);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);
        $app->instance('config', new Repository());
        $this->validator = new ValidatorFactory(new Translator(new ArrayLoader(), 'es'));
        $app->instance('validator', $this->validator);
        Request::macro('validate', function (array $rules, ...$params) {
            return app('validator')->validate($this->all(), $rules, ...$params);
        });

        $manager = new Manager($app);
        $manager->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], 'tenant');
        $database = $manager->getDatabaseManager();
        $database->setDefaultConnection('tenant');
        $app->instance('db', $database);
        Model::setConnectionResolver($database);
        Model::setEventDispatcher(new Dispatcher($app));
        $this->db = $database->connection('tenant');
        $this->validator->setPresenceVerifier(new \Illuminate\Validation\DatabasePresenceVerifier($database));

        foreach ([
            'configuration_ecommerce (id integer primary key, information_contact_name text null, information_contact_email text null, information_contact_phone text null, information_contact_address text null, logo text null, link_youtube text null, link_twitter text null, link_facebook text null, tag_support text null, tag_dollar text null, tag_shipping text null, token_public_culqui text null, token_private_culqui text null)',
            'tags (id integer primary key, name text, description text, status integer default 1)',
            'item_tags (id integer primary key, item_id integer, tag_id integer)',
            'users (id integer primary key)',
            'modules (id integer primary key, value text)',
            'module_user (module_id integer, user_id integer)',
            'module_levels (id integer primary key, value text, module_id integer)',
            'module_level_user (module_level_id integer, user_id integer)',
        ] as $table) {
            $this->db->statement('create table ' . substr($table, 0, -1) . ', created_at text null, updated_at text null)');
        }
        $this->db->table('configuration_ecommerce')->insert(['id' => 1, 'token_public_culqui' => 'pk_test_123', 'logo' => null]);
        TenantPlan::flush();
    }

    protected function tearDown(): void
    {
        TenantPlan::flush();
        $scopes = new \ReflectionProperty(Model::class, 'globalScopes');
        $scopes->setAccessible(true);
        $scopes->setValue(null, $this->previousScopes);
        if ($this->previousResolver) { Model::setConnectionResolver($this->previousResolver); }
        else { Model::unsetConnectionResolver(); }
        if ($this->previousDispatcher) { Model::setEventDispatcher($this->previousDispatcher); }
        else { Model::unsetEventDispatcher(); }
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacade);
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    private function post(array $data): Request
    {
        return Request::create('https://tenant.test/x', 'POST', $data);
    }

    private function rulesFail(string $formRequest, array $data): bool
    {
        $request = new $formRequest();
        $v = $this->validator->make($data, $request->rules(), method_exists($request, 'messages') ? $request->messages() : []);

        return $v->fails();
    }

    // ----------------------------------------------------------- QA-13 / QA-25

    public function test_publishing_a_product_requires_an_internal_code(): void
    {
        $this->db->statement('create table items (id integer primary key, internal_id text null, apply_store integer default 0, active integer default 1)');
        $make = function (array $data) {
            $request = \App\Http\Requests\Tenant\ItemRequest::create('/items', 'POST', $data);
            return $this->validator->make($data, $request->rules(), $request->messages());
        };

        $this->assertTrue($make(['apply_store' => true, 'internal_id' => null])->errors()->has('internal_id'));
        $this->assertTrue($make(['apply_store' => 'true', 'internal_id' => ''])->errors()->has('internal_id'));
        $this->assertFalse($make(['apply_store' => true, 'internal_id' => 'A-1'])->errors()->has('internal_id'));
        $this->assertFalse($make(['apply_store' => false, 'internal_id' => null])->errors()->has('internal_id'));
    }

    public function test_tag_names_are_unique_among_active_tags_and_unknown_ids_are_not_created(): void
    {
        $this->db->table('tags')->insert([['id' => 1, 'name' => 'Audio', 'description' => null, 'status' => 1], ['id' => 2, 'name' => 'Viejo', 'description' => null, 'status' => 0]]);

        $this->assertTrue($this->rulesFail(TagRequest::class, ['name' => 'Audio']));
        $this->assertFalse($this->rulesFail(TagRequest::class, ['name' => 'Viejo']));   // removed tags free their name
        $edit = TagRequest::create('/tags', 'POST', ['id' => 1, 'name' => 'Audio']);    // editing itself
        $this->assertFalse($this->validator->make($edit->all(), $edit->rules(), $edit->messages())->fails());

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $request = TagRequest::create('/tags', 'POST', ['id' => 999, 'name' => 'Nuevo']);
        (new TagController())->store($request);
    }

    // ----------------------------------------------------------- QA-02

    public function test_saving_the_logo_card_does_not_erase_the_culqi_public_token(): void
    {
        $controller = new ConfigurationController();

        // What the old logo "Guardar" button posted.
        $controller->store_configuration_culqui($this->post(['id' => 1, 'logo_store' => 'x.png']));
        $this->assertSame('pk_test_123', $this->db->table('configuration_ecommerce')->value('token_public_culqui'));

        // The Culqi card sends the field and may clear it on purpose.
        $controller->store_configuration_culqui($this->post(['token_public_culqui' => 'pk_test_456']));
        $this->assertSame('pk_test_456', $this->db->table('configuration_ecommerce')->value('token_public_culqui'));
        $controller->store_configuration_culqui($this->post(['token_public_culqui' => '']));
        $this->assertEmpty($this->db->table('configuration_ecommerce')->value('token_public_culqui'));
    }

    public function test_culqi_keys_need_the_expected_prefix_and_a_matching_mode(): void
    {
        $controller = new ConfigurationController();

        foreach ([
            ['token_public_culqui' => 'sk_test_123'],
            ['token_public_culqui' => 'abc'],
            ['token_private_culqui' => 'pk_test_123'],
            ['token_private_culqui' => 'sk_live_x', 'token_public_culqui' => 'pk_test_123'], // mixed modes
        ] as $bad) {
            try {
                $controller->store_configuration_culqui($this->post($bad));
                $this->fail('accepted '.json_encode($bad));
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }
        $this->assertSame('pk_test_123', $this->db->table('configuration_ecommerce')->value('token_public_culqui'));
    }

    public function test_saved_record_exposes_only_the_presence_of_the_private_key(): void
    {
        $controller = new ConfigurationController();
        $response = $controller->store_configuration_culqui($this->post(['token_public_culqui' => 'pk_live_abc']));

        $this->assertTrue($response['success']);
        $this->assertSame('live', $response['record']['culqi_mode']);
        $this->assertFalse($response['record']['has_token_private_culqui']);
        $this->assertFalse($response['record']['card_enabled']);
        $this->assertArrayNotHasKey('token_private_culqui', $response['record']);
        $this->assertSame('pk_live_abc', $response['record']['token_public_culqui']);
    }

    // ----------------------------------------------------------- QA-16

    public function test_social_links_must_be_http_urls(): void
    {
        $controller = new ConfigurationController();

        foreach (['javascript:alert(document.domain)', 'not a url', 'ftp://x.test/a', 'data:text/html,x', str_repeat('a', 300)] as $bad) {
            try {
                $controller->store_configuration_social($this->post(['link_facebook' => $bad]));
                $this->fail('accepted '.substr($bad, 0, 30));
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('link_facebook', $e->errors());
            }
        }
        $this->assertNull($this->db->table('configuration_ecommerce')->value('link_facebook'));

        $this->assertTrue($controller->store_configuration_social($this->post(['link_facebook' => 'https://facebook.com/tienda', 'link_twitter' => null, 'link_youtube' => '']))['success']);
        $this->assertSame('https://facebook.com/tienda', $this->db->table('configuration_ecommerce')->value('link_facebook'));
    }

    // ----------------------------------------------------------- QA-18

    public function test_form_requests_reject_oversized_and_non_string_input(): void
    {
        $this->assertFalse($this->rulesFail(TagRequest::class, ['name' => 'Audio', 'description' => 'Audio']));
        $this->assertTrue($this->rulesFail(TagRequest::class, ['name' => str_repeat('x', 400), 'description' => 'd']));
        $this->assertTrue($this->rulesFail(TagRequest::class, ['name' => ['a'], 'description' => 'd']));

        $promo = ['name' => 'Promo', 'description' => 'd', 'item_id' => 1, 'image' => 'a.png'];
        $this->db->statement('create table items (id integer primary key, apply_store integer default 0, active integer default 1)');
        $this->db->table('items')->insert([['id' => 1, 'apply_store' => 1, 'active' => 1], ['id' => 2, 'apply_store' => 0, 'active' => 1]]);
        $this->assertFalse($this->rulesFail(PromotionRequest::class, $promo));
        $this->assertTrue($this->rulesFail(PromotionRequest::class, array_merge($promo, ['name' => str_repeat('x', 300)])));
        $this->assertTrue($this->rulesFail(PromotionRequest::class, array_merge($promo, ['item_id' => 'abc'])));
        $this->assertTrue($this->rulesFail(PromotionRequest::class, array_merge($promo, ['item_id' => 999])));
        // QA-17: a promotion can only point to a published product.
        $this->assertTrue($this->rulesFail(PromotionRequest::class, array_merge($promo, ['item_id' => 2])));

        $contact = ['information_contact_name' => 'Tienda', 'information_contact_email' => 'a@b.pe', 'information_contact_phone' => '987 654 321', 'information_contact_address' => 'Av 1'];
        $this->assertFalse($this->rulesFail(ConfigurationEcommerceRequest::class, $contact));
        $this->assertTrue($this->rulesFail(ConfigurationEcommerceRequest::class, array_merge($contact, ['information_contact_name' => str_repeat('x', 300)])));
        $this->assertTrue($this->rulesFail(ConfigurationEcommerceRequest::class, array_merge($contact, ['information_contact_email' => 'no-es-email'])));
        $this->assertTrue($this->rulesFail(ConfigurationEcommerceRequest::class, array_merge($contact, ['information_contact_phone' => 'llamar mañana'])));
    }

    // ----------------------------------------------------------- QA-10

    public function test_a_tag_assigned_to_products_cannot_be_deleted(): void
    {
        $this->db->table('tags')->insert([['id' => 1, 'name' => 'Audio', 'description' => 'Audio', 'status' => 1], ['id' => 2, 'name' => 'Libre', 'description' => 'Libre', 'status' => 1]]);
        $this->db->table('item_tags')->insert(['id' => 1, 'item_id' => 5, 'tag_id' => 1]);
        $controller = new TagController();

        $blocked = $controller->destroy(1);
        $this->assertFalse($blocked['success']);
        $this->assertStringContainsString('asignada a 1 producto', $blocked['message']);
        $this->assertSame(1, (int) $this->db->table('tags')->where('id', 1)->value('status'));

        $this->assertTrue($controller->destroy(2)['success']);
        $this->assertSame(0, (int) $this->db->table('tags')->where('id', 2)->value('status'));
    }

    // ----------------------------------------------------------- QA-12

    public function test_registration_validates_name_email_and_password(): void
    {
        $controller = (new \ReflectionClass(EcommerceController::class))->newInstanceWithoutConstructor();
        $ok = ['name' => 'Ana', 'email' => 'ana@example.test', 'pswd' => 'secret-pass', 'pswd_rpt' => 'secret-pass'];

        foreach ([
            [['email' => 'notanemail'], 'correo electrónico no es válido'],
            [['email' => ''], 'correo electrónico es obligatorio'],
            [['name' => ''], 'nombre es obligatorio'],
            [['name' => str_repeat('x', 300)], 'nombre no debe superar'],
            [['pswd' => '1', 'pswd_rpt' => '1'], 'al menos 8 caracteres'],
            [['pswd_rpt' => 'other-pass'], 'no coinciden'],
            [['pswd_rpt' => ''], 'Repita la contraseña'],
        ] as [$override, $needle]) {
            $r = $controller->storeUser($this->post(array_merge($ok, $override)));
            $this->assertFalse($r['success'], json_encode($override));
            $this->assertStringContainsString($needle, $r['message']);
        }
    }

    // ----------------------------------------------------------- QA-20

    public function test_storefront_is_a_404_when_the_plan_has_no_ecommerce_module(): void
    {
        $this->db->table('modules')->insert([['id' => 1, 'value' => 'documents'], ['id' => 10, 'value' => 'ecommerce']]);
        $this->db->table('users')->insert(['id' => 1]);
        $next = function () { return 'store'; };
        $middleware = new RequireStorefrontModule();
        $request = Request::create('https://tenant.test/ecommerce');

        // The owner (lowest user id) only has "documents": no storefront.
        $this->db->table('module_user')->insert(['module_id' => 1, 'user_id' => 1]);
        TenantPlan::flush();
        try {
            $middleware->handle($request, $next, 'ecommerce');
            $this->fail('storefront served without the module');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }

        $this->db->table('module_user')->insert(['module_id' => 10, 'user_id' => 1]);
        TenantPlan::flush();
        $this->assertSame('store', $middleware->handle($request, $next, 'ecommerce'));
    }
}
