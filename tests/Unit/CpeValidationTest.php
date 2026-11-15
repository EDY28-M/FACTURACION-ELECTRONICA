<?php

namespace Tests\Unit;

use App\CoreFacturalo\Services\Cpe\PurchaseValidationService;
use App\CoreFacturalo\WS\Cpe\CpeValidationClient;
use App\CoreFacturalo\WS\Gre\Exceptions\GreApiException;
use App\CoreFacturalo\WS\Gre\Exceptions\GreAuthException;
use App\CoreFacturalo\WS\Gre\Exceptions\GreConfigurationException;
use App\CoreFacturalo\WS\Gre\GreClient;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use PHPUnit\Framework\TestCase;

/**
 * Every HTTP exchange is served by a Guzzle MockHandler: no real call to SUNAT is possible.
 */
class CpeValidationTest extends TestCase
{
    private $history = [];

    private $settings = [
        'ruc' => '20123456789',
        'token_url' => 'https://token.test/v1',
        'api_url' => 'https://api.test/v1/contribuyente',
        'client_id' => 'cid',
        'client_secret' => 'secret',
    ];

    private function client(array $responses, Repository $cache = null)
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $this->history = [];
        $stack->push(Middleware::history($this->history));

        return new CpeValidationClient($this->settings, new Client(['handler' => $stack]), $cache ?: new Repository(new ArrayStore()));
    }

    private function json($status, array $body)
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($body));
    }

    private function token($value = 'TOKEN1')
    {
        return $this->json(200, ['access_token' => $value, 'token_type' => 'JWT', 'expires_in' => 3600]);
    }

    private function ok(array $data)
    {
        return $this->json(200, ['success' => true, 'message' => 'Operacion realizada con exito', 'data' => $data]);
    }

    private function payload()
    {
        return ['numRuc' => '20100070970', 'codComp' => '01', 'numeroSerie' => 'F001', 'numero' => 15, 'fechaEmision' => '05/11/2026', 'monto' => 118.0];
    }

    public function testTokenRequestUsesClientCredentialsOnExtranetPath()
    {
        $client = $this->client([$this->token(), $this->ok(['estadoCp' => '1', 'estadoRuc' => '00', 'condDomiRuc' => '00'])]);
        $client->validate($this->payload());

        $token = $this->history[0]['request'];
        $this->assertSame('POST', $token->getMethod());
        $this->assertSame('https://token.test/v1/clientesextranet/cid/oauth2/token/', (string) $token->getUri());
        parse_str((string) $token->getBody(), $form);
        $this->assertSame('client_credentials', $form['grant_type']);
        $this->assertSame('https://api.sunat.gob.pe/v1/contribuyente/contribuyentes', $form['scope']);
        $this->assertSame('cid', $form['client_id']);
        $this->assertSame('secret', $form['client_secret']);
        $this->assertArrayNotHasKey('username', $form);
        $this->assertArrayNotHasKey('password', $form);
    }

    public function testValidateRequestShape()
    {
        $client = $this->client([$this->token(), $this->ok(['estadoCp' => '1'])]);
        $client->validate($this->payload());

        $req = $this->history[1]['request'];
        $this->assertSame('POST', $req->getMethod());
        $this->assertSame('https://api.test/v1/contribuyente/contribuyentes/20123456789/validarcomprobante', (string) $req->getUri());
        $this->assertSame('Bearer TOKEN1', $req->getHeaderLine('Authorization'));
        $this->assertEquals($this->payload(), json_decode((string) $req->getBody(), true));
    }

    public function testTokenIsCachedAndDistinctFromGreAndSireKeys()
    {
        $cache = new Repository(new ArrayStore());
        $client = $this->client([$this->token(), $this->ok(['estadoCp' => '1']), $this->ok(['estadoCp' => '1'])], $cache);
        $client->validate($this->payload());
        $client->validate($this->payload());
        $this->assertCount(3, $this->history, 'one token request + two validations');

        $gre = new GreClient($this->settings + ['username' => 'u', 'password' => 'p'], new Client(['handler' => HandlerStack::create(new MockHandler([$this->token('GRE')]))]), $cache);
        $this->assertSame('GRE', $gre->getToken());
    }

    public function testRefreshesTokenOnceOn401()
    {
        $client = $this->client([$this->token('OLD'), $this->json(401, ['message' => 'expired']), $this->token('NEW'), $this->ok(['estadoCp' => '1'])]);
        $client->validate($this->payload());
        $this->assertSame('Bearer NEW', $this->history[3]['request']->getHeaderLine('Authorization'));
    }

    public function testErrorMapping()
    {
        try {
            $this->client([$this->token(), $this->json(401, []), $this->token(), $this->json(401, [])])->validate($this->payload());
            $this->fail('expected auth exception');
        } catch (GreAuthException $e) {
            $this->assertSame(401, $e->getHttpStatus());
        }

        try {
            $this->client([$this->token(), $this->json(429, ['message' => 'Too many', 'errorCode' => 'E429'])])->validate($this->payload());
            $this->fail('expected api exception');
        } catch (GreApiException $e) {
            $this->assertSame(429, $e->getHttpStatus());
            $this->assertSame('E429', $e->getSunatCode());
        }

        try {
            $this->client([$this->token(), $this->json(500, [])])->validate($this->payload());
            $this->fail('expected api exception');
        } catch (GreApiException $e) {
            $this->assertSame(500, $e->getHttpStatus());
        }

        try {
            $this->client([$this->token(), $this->json(200, ['success' => false, 'message' => 'RUC invalido', 'errorCode' => 'E1'])])->validate($this->payload());
            $this->fail('expected api exception');
        } catch (GreApiException $e) {
            $this->assertSame(200, $e->getHttpStatus());
            $this->assertSame('RUC invalido', $e->getMessage());
        }
    }

    public function testMissingSettingsAreRejectedWithoutUsernamePassword()
    {
        $this->expectException(GreConfigurationException::class);
        new CpeValidationClient(['ruc' => '20123456789', 'token_url' => 'x', 'api_url' => 'y', 'client_id' => 'cid']);
    }

    public function testBuildRequestElectronicIncludesMontoAndFormatsFields()
    {
        $r = PurchaseValidationService::buildRequest($this->doc(['series' => 'f001', 'number' => '00000015', 'total' => '118.005']));
        $this->assertSame([
            'numRuc' => '20100070970', 'codComp' => '01', 'numeroSerie' => 'F001',
            'numero' => 15, 'fechaEmision' => '05/11/2026', 'monto' => 118.01,
        ], $r['payload']);
        $this->assertIsInt($r['payload']['numero']);
    }

    public function testBuildRequestPrintedSeriesOmitsMonto()
    {
        $r = PurchaseValidationService::buildRequest($this->doc(['series' => '0001']));
        $this->assertArrayNotHasKey('monto', $r['payload']);
    }

    public function testBuildRequestSkipsUnsupportedOrNonRuc()
    {
        $this->assertArrayHasKey('skip', PurchaseValidationService::buildRequest($this->doc(['document_type_id' => '12'])));
        $this->assertArrayHasKey('skip', PurchaseValidationService::buildRequest($this->doc(['supplier' => ['identity_document_type_id' => '1', 'number' => '12345678']])));
        $this->assertArrayHasKey('skip', PurchaseValidationService::buildRequest($this->doc(['series' => 'F01'])));
        $this->assertArrayHasKey('skip', PurchaseValidationService::buildRequest($this->doc(['number' => 'ABC'])));
    }

    public function testResponseMappingForEveryEstadoCp()
    {
        $expected = [0 => 'no_existe', 1 => 'aceptado', 2 => 'anulado', 3 => 'autorizado', 4 => 'no_autorizado'];
        foreach ($expected as $code => $status) {
            $m = PurchaseValidationService::mapResponse(['estadoCp' => (string) $code, 'estadoRuc' => '00', 'condDomiRuc' => '12', 'Observaciones' => ['obs']]);
            $this->assertSame($status, $m['status']);
            $this->assertSame('ACTIVO', $m['detail']['estadoRucLabel']);
            $this->assertSame('NO HABIDO', $m['detail']['condDomiRucLabel']);
            $this->assertSame(['obs'], $m['detail']['observaciones']);
        }

        $this->assertSame('error', PurchaseValidationService::mapResponse(['estadoCp' => '9'])['status']);
        $this->assertSame('error', PurchaseValidationService::mapResponse([])['status']);
    }

    public function testServiceStoresNoApplicaWithoutHttp()
    {
        $service = new RecordingValidationService($this->client([]));
        $purchase = $this->purchase(['document_type_id' => '12']);
        $result = $service->validatePurchase($purchase);

        $this->assertSame('no_aplica', $result['status']);
        $this->assertCount(0, $this->history);
        $this->assertSame('no_aplica', $service->stored[0]['status']);
    }

    public function testServiceStoresResultAndRecordsSuccessFalseAsError()
    {
        $service = new RecordingValidationService($this->client([
            $this->token(),
            $this->ok(['estadoCp' => '2', 'estadoRuc' => '00', 'condDomiRuc' => '00']),
            $this->json(200, ['success' => false, 'message' => 'Fallo', 'errorCode' => 'X']),
        ]));

        $this->assertSame('anulado', $service->validatePurchase($this->purchase())['status']);
        $err = $service->validatePurchase($this->purchase());
        $this->assertSame('error', $err['status']);
        $this->assertSame('Fallo', $err['detail']['message']);
    }

    public function testBatchStopsAtRateLimitAndReportsProgress()
    {
        $service = new RecordingValidationService($this->client([
            $this->token(),
            $this->ok(['estadoCp' => '1']),
            $this->ok(['estadoCp' => '0']),
            $this->json(429, ['message' => 'Too many']),
        ]));

        $rows = [$this->purchase([], 1), $this->purchase([], 2), $this->purchase([], 3), $this->purchase([], 4)];
        $batch = $service->validateBatch($rows);

        $this->assertTrue($batch['stopped']);
        $this->assertSame(2, $batch['processed']);
        $this->assertSame(2, $batch['last_id']);
        $this->assertSame(['aceptado' => 1, 'no_existe' => 1], $batch['by_status']);
        $this->assertNotEmpty($batch['message']);
    }

    public function testVoidedPurchaseIsNoAplicaWithoutHttp()
    {
        $service = new RecordingValidationService($this->client([]));
        $purchase = $this->purchase();
        $purchase->state_type_id = '11';

        $this->assertSame('no_aplica', $service->validatePurchase($purchase)['status']);
        $this->assertCount(0, $this->history);
    }

    public function testBatchReportsPartialProgressWhenAServerErrorInterrupts()
    {
        $service = new RecordingValidationService($this->client([$this->token(), $this->ok(['estadoCp' => '1']), $this->json(500, [])]));

        try {
            $service->validateBatch([$this->purchase([], 1), $this->purchase([], 2)]);
            $this->fail('expected interruption');
        } catch (\App\CoreFacturalo\Services\Cpe\BulkValidationInterrupted $e) {
            $this->assertSame(1, $e->getPartial()['processed']);
            $this->assertSame(1, $e->getPartial()['next_after_id']);
            $this->assertSame(500, $e->getCause()->getHttpStatus());
        }
    }

    public function testBatchStopsWhenTimeBudgetIsUsed()
    {
        $service = new RecordingValidationService($this->client([$this->token(), $this->ok(['estadoCp' => '1'])]));
        $service->budget = -1; // already exhausted after the first document
        $batch = $service->validateBatch([$this->purchase([], 1), $this->purchase([], 2)]);

        $this->assertSame(1, $batch['processed']);
        $this->assertSame(1, $batch['last_id']);
        $this->assertFalse($batch['stopped']);
    }

    public function testFingerprintChangesOnlyWithValidatedFields()
    {
        $a = $this->purchase();
        $b = $this->purchase(['number' => '16']);
        $c = $this->purchase();
        $c->payment_condition_irrelevant = 'x';
        $this->assertNotSame(PurchaseValidationService::fingerprint($a), PurchaseValidationService::fingerprint($b));
        $this->assertSame(PurchaseValidationService::fingerprint($a), PurchaseValidationService::fingerprint($c));
    }

    private function doc(array $override = [])
    {
        return $override + [
            'document_type_id' => '01',
            'series' => 'F001',
            'number' => '15',
            'date_of_issue' => '2026-11-05',
            'total' => 118,
            'supplier' => ['identity_document_type_id' => '6', 'number' => '20100070970'],
        ];
    }

    private function purchase(array $override = [], $id = 1)
    {
        $doc = $override + $this->doc();
        $purchase = new \stdClass();
        $purchase->id = $id;
        $purchase->document_type_id = $doc['document_type_id'];
        $purchase->series = $doc['series'];
        $purchase->number = $doc['number'];
        $purchase->date_of_issue = Carbon::parse($doc['date_of_issue']);
        $purchase->total = $doc['total'];
        $purchase->currency_type_id = 'PEN';
        $purchase->supplier_id = 7;
        $purchase->supplier = (object) $doc['supplier'];

        return $purchase;
    }
}

/** Skips persistence so the service can be exercised without a database. */
class RecordingValidationService extends PurchaseValidationService
{
    public $stored = [];

    public $budget = 20;

    protected function timeBudget()
    {
        return $this->budget;
    }

    protected function store($purchase, $status, array $detail)
    {
        $this->stored[] = ['status' => $status, 'detail' => $detail];

        return ['status' => $status, 'label' => self::LABELS[$status], 'detail' => $detail, 'validated_at' => null];
    }
}
