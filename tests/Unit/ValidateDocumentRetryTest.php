<?php

namespace Tests\Unit;

use App\Models\Tenant\Document;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Facade;
use Modules\Document\Http\Controllers\ValidateDocumentController;
use Modules\Document\Http\Requests\ValidateDocumentsRequest;
use PHPUnit\Framework\TestCase;

class FixtureValidateDocumentController extends ValidateDocumentController
{
    public $responses = [];
    public $calls = [];

    protected function lookupDocument($document)
    {
        $this->calls[] = ['id' => $document->id, 'transaction' => $document->getConnection()->transactionLevel()];
        // Finite fake budget makes a missing retry bound fail without any external lookup.
        if (empty($this->responses[$document->id])) { throw new \RuntimeException('Fixture lookup budget exhausted'); }
        $result = array_shift($this->responses[$document->id]);
        if ($result instanceof \Throwable) { throw $result; }
        return is_callable($result) ? $result($document) : $result;
    }
}

class ValidateDocumentRetryTest extends TestCase
{
    private $previousContainer;
    private $previousFacade;
    private $previousResolver;
    private $previousDispatcher;
    private $previousScopes;
    private $previousPageResolver;
    private $previousPathResolver;
    private $app;
    private $db;
    private $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $this->previousFacade = Facade::getFacadeApplication();
        $this->previousResolver = Model::getConnectionResolver();
        $this->previousDispatcher = Model::getEventDispatcher();
        foreach (['globalScopes' => 'previousScopes'] as $property => $target) {
            $reflection = new \ReflectionProperty(Model::class, $property);
            $reflection->setAccessible(true);
            $this->$target = $reflection->getValue();
        }
        foreach (['currentPageResolver' => 'previousPageResolver', 'currentPathResolver' => 'previousPathResolver'] as $property => $target) {
            $reflection = new \ReflectionProperty(Paginator::class, $property);
            $reflection->setAccessible(true);
            $this->$target = $reflection->getValue();
        }
        Paginator::currentPageResolver(fn () => 1);
        Paginator::currentPathResolver(fn () => '/fixture/validate-documents/records');
        $this->app = new Container();
        Container::setInstance($this->app);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);
        $this->app->instance('config', new Repository(['tenant' => ['items_per_page' => 2]]));
        $capsule = new Manager($this->app);
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], 'tenant');
        $database = $capsule->getDatabaseManager();
        $database->setDefaultConnection('tenant');
        $this->app->instance('db', $database);
        Model::setConnectionResolver($database);
        Model::setEventDispatcher(new Dispatcher($this->app));
        $this->db = $database->connection('tenant');
        // Pagination/state writes are real; unrelated catalogue relations are omitted from fixtures.
        Document::addGlobalScope('validation-fixture', fn ($query) => $query->setEagerLoads([]));
        $this->db->statement('create table documents (id integer primary key, state_type_id text, document_type_id text, series text, number integer, date_of_issue text, total numeric, created_at text, updated_at text)');
        for ($id = 1; $id <= 7; $id++) {
            $this->db->table('documents')->insert(['id' => $id, 'state_type_id' => '01', 'document_type_id' => '01', 'series' => 'F001', 'number' => $id, 'date_of_issue' => '2026-11-01', 'total' => 100, 'created_at' => '2026-11-01 00:00:00', 'updated_at' => '2026-11-01 00:00:00']);
        }
        $this->controller = new FixtureValidateDocumentController();
    }

    protected function tearDown(): void
    {
        $reflection = new \ReflectionProperty(Model::class, 'globalScopes');
        $reflection->setAccessible(true);
        $reflection->setValue(null, $this->previousScopes);
        foreach (['currentPageResolver' => 'previousPageResolver', 'currentPathResolver' => 'previousPathResolver'] as $property => $target) {
            $reflection = new \ReflectionProperty(Paginator::class, $property);
            $reflection->setAccessible(true);
            $reflection->setValue(null, $this->$target);
        }
        if ($this->previousResolver) { Model::setConnectionResolver($this->previousResolver); }
        else { Model::unsetConnectionResolver(); }
        if ($this->previousDispatcher) { Model::setEventDispatcher($this->previousDispatcher); }
        else { Model::unsetEventDispatcher(); }
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacade);
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    private function response(string $description = 'ACEPTADO', $code = '1'): array
    {
        return ['success' => true, 'data' => ['comprobante_estado_codigo' => $code, 'comprobante_estado_descripcion' => $description]];
    }

    private function request(int $end = 2): ValidateDocumentsRequest
    {
        return ValidateDocumentsRequest::create('/fixture/regularize', 'POST', ['document_type_id' => '01', 'series' => 'F001', 'start_number' => 1, 'end_number' => $end]);
    }

    private function states(): array
    {
        return $this->db->table('documents')->orderBy('id')->pluck('state_type_id')->all();
    }

    private function assertOutsideTransaction(): void
    {
        $this->assertNotEmpty($this->controller->calls);
        $this->assertSame([0], array_values(array_unique(array_column($this->controller->calls, 'transaction'))));
        $this->assertSame(0, $this->db->transactionLevel());
    }

    public function test_read_pagination_bounds_retries_preserves_success_and_returns_generic_failure_fields(): void
    {
        $this->controller->responses = [1 => [['success' => false], $this->response()], 2 => [['success' => false], ['success' => false], ['success' => false]]];
        $page = $this->controller->validateDocuments(Document::orderBy('id'));
        $this->assertSame(7, $page->total());
        $this->assertSame(2, $page->perPage());
        $this->assertSame(1, $page->currentPage());
        $rows = $page->getCollection();
        $this->assertSame('1', $rows[0]->code);
        $this->assertSame('ACEPTADO', $rows[0]->state_type_sunat_description);
        $this->assertSame('F001-1|Código: 1|Mensaje: ACEPTADO', $rows[0]->message);
        $this->assertNull($rows[1]->code);
        $this->assertIsString($rows[1]->message);
        $this->assertNotSame('', $rows[1]->message);
        $this->assertIsString($rows[1]->state_type_sunat_description);
        $this->assertSame([1, 1, 2, 2, 2], array_column($this->controller->calls, 'id'));
        $this->assertSame(array_fill(0, 7, '01'), $this->states());
        $this->assertOutsideTransaction();
    }

    public function test_malformed_and_exception_outcomes_are_retried_with_a_finite_budget(): void
    {
        $this->controller->responses = [1 => [null, new \RuntimeException('fixture-private-detail'), $this->response('ANULADO', 2)], 2 => [['success' => true, 'data' => []], ['success' => true, 'data' => ['comprobante_estado_codigo' => [], 'comprobante_estado_descripcion' => '']], ['success' => false]]];
        $page = $this->controller->validateDocuments(Document::orderBy('id'));
        $this->assertSame(2, $page->getCollection()[0]->code);
        $this->assertNull($page->getCollection()[1]->code);
        $this->assertStringNotContainsString('fixture-private-detail', json_encode($page->getCollection()));
        $this->assertCount(6, $this->controller->calls);
        $this->assertOutsideTransaction();
    }

    public function test_regularize_gathers_all_outcomes_without_transaction_and_preserves_all_six_mappings_and_events(): void
    {
        $descriptions = ['aceptado', 'ENVIADO', 'OBSERVADO', 'RECHAZADO', 'ANULADO', 'POR ANULAR', 'AUTORIZADO'];
        foreach ($descriptions as $index => $description) { $this->controller->responses[$index + 1] = [$this->response($description)]; }
        $this->controller->responses[1] = [['success' => false], $this->response('aceptado')];
        $events = [];
        Document::updated(function ($document) use (&$events) { $events[] = ['id' => $document->id, 'state' => $document->state_type_id, 'transaction' => $document->getConnection()->transactionLevel()]; });
        $this->assertSame(['success' => true, 'message' => 'Estados regularizados correctamente'], $this->controller->regularize($this->request(7)));
        $this->assertSame(['05', '03', '07', '09', '11', '13', '01'], $this->states());
        $this->assertCount(6, $events);
        $this->assertSame([1], array_values(array_unique(array_column($events, 'transaction'))));
        $this->assertCount(8, $this->controller->calls);
        $this->assertOutsideTransaction();
    }

    public function test_exhausted_second_document_does_not_write_or_emit_any_update_event(): void
    {
        $this->controller->responses = [1 => [$this->response()], 2 => [['success' => false], ['success' => false], ['success' => false]]];
        $events = [];
        Document::updated(function ($document) use (&$events) { $events[] = $document->id; });
        $result = $this->controller->regularize($this->request());
        $this->assertFalse($result['success']);
        $this->assertIsString($result['message']);
        $this->assertSame(array_fill(0, 7, '01'), $this->states());
        $this->assertSame([], $events);
        $this->assertCount(4, $this->controller->calls);
        $this->assertOutsideTransaction();
    }

    public function test_write_failure_rolls_back_the_whole_batch_and_uses_generic_response(): void
    {
        $this->controller->responses = [1 => [$this->response()], 2 => [$this->response('ANULADO', '2')]];
        $events = [];
        Document::updated(function ($document) use (&$events) { $events[] = $document->id; });
        Document::updating(function ($document) { if ($document->id === 2) { throw new \RuntimeException('fixture-write-private-detail'); } });
        $result = $this->controller->regularize($this->request());
        $this->assertFalse($result['success']);
        $this->assertStringNotContainsString('fixture-write-private-detail', json_encode($result));
        $this->assertSame(array_fill(0, 7, '01'), $this->states());
        $this->assertSame([1], $events);
        $this->assertOutsideTransaction();
    }

    public function test_intervening_state_transition_is_not_overwritten_and_rolls_back_other_planned_changes(): void
    {
        $this->controller->responses = [1 => [$this->response()], 2 => [function () {
            $this->db->table('documents')->where('id', 2)->update(['state_type_id' => '13']);
            return $this->response('ANULADO', '2');
        }]];
        $events = [];
        Document::updated(function ($document) use (&$events) { $events[] = $document->id; });
        $result = $this->controller->regularize($this->request());
        $this->assertFalse($result['success']);
        $this->assertSame([], $events);
        $this->assertSame(['01', '13', '01', '01', '01', '01', '01'], $this->states());
        $this->assertOutsideTransaction();
    }
    public function test_deterministic_row_lock_order_preserves_original_logical_event_order(): void
    {
        $this->db->table('documents')->where('id', 2)->update(['created_at' => '2026-11-02 00:00:00']);
        $this->controller->responses = [1 => [$this->response()], 2 => [$this->response('ANULADO', '2')]];
        $events = [];
        Document::updated(function ($document) use (&$events) { $events[] = $document->id; });
        $this->assertTrue($this->controller->regularize($this->request())['success']);
        $this->assertSame([2, 1], array_column($this->controller->calls, 'id'));
        $this->assertSame([2, 1], $events);
    }

}
