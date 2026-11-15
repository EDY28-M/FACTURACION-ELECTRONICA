<?php

namespace Tests\Unit;

use App\CoreFacturalo\Services\Sire\SirePropuestaParser as P;
use App\CoreFacturalo\Services\Sire\SireReconciliationService;
use App\CoreFacturalo\Services\Sire\SireRequestStore;
use App\CoreFacturalo\WS\Gre\Exceptions\GreApiException;
use App\CoreFacturalo\WS\Sire\SireClient;
use App\Models\Tenant\SireRequest;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use PHPUnit\Framework\TestCase;

/**
 * Service-level tests: MockHandler for HTTP, in-memory store instead of the database.
 */
class SireReconciliationServiceTest extends TestCase
{
    /** @var MockHandler */
    private $mock;

    /** @var InMemorySireStore */
    private $store;

    private function service(array $responses, callable $system = null): SireReconciliationService
    {
        $this->mock = new MockHandler(array_merge([$this->json(200, ['access_token' => 'T', 'expires_in' => 3600])], $responses));
        $this->store = $this->store ?: new InMemorySireStore();
        $client = new SireClient([
            'ruc' => '20123456789', 'token_url' => 'https://token.test/v1', 'api_url' => 'https://sire.test/v1',
            'client_id' => 'c', 'client_secret' => 's', 'username' => 'u', 'password' => 'p',
        ], new Client(['handler' => HandlerStack::create($this->mock)]), new Repository(new ArrayStore()));

        return new SireReconciliationService($client, $this->store, $system ?: function () {
            return ['rows' => [['type' => '01', 'series' => 'F001', 'number' => '1', 'date' => '2026-10-05', 'total' => 118.0, 'state' => '05']], 'excluded' => []];
        });
    }

    private function json($status, array $body)
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($body));
    }

    private function zip(string $txt): string
    {
        $path = tempnam(sys_get_temp_dir(), 'sz');
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::OVERWRITE);
        $zip->addFromString('p.txt', $txt);
        $zip->close();
        $bin = file_get_contents($path);
        unlink($path);

        return $bin;
    }

    private function ready(string $ticket = 'T1'): Response
    {
        return $this->json(200, ['registros' => [['numTicket' => $ticket, 'codProceso' => '10', 'codEstadoProceso' => '06', 'archivoReporte' => [['nomArchivoReporte' => 'p.zip']]]]]);
    }

    private function empty1070(): Response
    {
        return $this->json(422, ['cod' => '422', 'errors' => [['cod' => '1070', 'msg' => 'sin comprobantes']]]);
    }

    public function testEmptyProposal1070ReconcilesAgainstSystem()
    {
        $r = $this->service([$this->empty1070()])->request('202610');

        $this->assertSame(SireRequest::STATUS_RECONCILED, $r['request']->status);
        $this->assertSame(0, $r['request']->summary['sunat']['count']);
        $this->assertSame(1, $r['request']->summary['solo_en_sistema_count']);
    }

    public function testEmptyProposal1070FailureMarksFailedAndAllowsNewRequest()
    {
        $broken = function () {
            throw new \RuntimeException('db down');
        };
        $svc = $this->service([$this->empty1070()], $broken);
        try {
            $svc->request('202610');
            $this->fail('expected exception');
        } catch (\RuntimeException $e) {
            $this->assertCount(1, $this->store->all);
            $this->assertSame(SireRequest::STATUS_FAILED, $this->store->all[0]->status);
        }

        $again = $this->service([$this->json(200, ['numTicket' => 'T2'])])->request('202610');
        $this->assertSame('T2', $again['request']->ticket);
    }

    public function testExportErrorMarksFailedAndAllowsNewRequest()
    {
        try {
            $this->service([$this->json(503, [])])->request('202610');
            $this->fail('expected exception');
        } catch (GreApiException $e) {
            $this->assertSame(SireRequest::STATUS_FAILED, $this->store->all[0]->status);
        }

        $again = $this->service([$this->json(200, ['numTicket' => 'T2'])])->request('202610');
        $this->assertSame('T2', $again['request']->ticket);
    }

    public function testDownloadFailureMarksFailedAndReRequestIsAllowed()
    {
        $svc = $this->service([$this->json(200, ['numTicket' => 'T1']), $this->json(200, ['registros' => [['numTicket' => 'T1', 'codEstadoProceso' => '05']]]), $this->ready(), $this->json(500, [])]);
        $record = $svc->request('202610')['request'];
        $svc->refresh($record); // processing
        $out = $svc->refresh($record); // ready -> download 500

        $this->assertSame(SireRequest::STATUS_FAILED, $out['request']->status);
        $this->assertStringContainsString('no está disponible', $out['request']->status_detail);

        $again = $this->service([$this->json(200, ['numTicket' => 'T2'])])->request('202610');
        $this->assertSame('T2', $again['request']->ticket);
        $this->assertCount(2, $this->store->all);
    }

    public function testExpiredOpenRecordsDoNotBlockANewRequest()
    {
        $this->store = new InMemorySireStore();
        $old = $this->store->save(new SireRequest(['book' => '140000', 'period' => '202610', 'ticket' => 'OLD', 'status' => 'processing', 'requested_at' => Carbon::now()->subHours(25)]));
        $noTicket = $this->store->save(new SireRequest(['book' => '140000', 'period' => '202610', 'status' => 'requested', 'requested_at' => Carbon::now()->subMinutes(10)]));

        $r = $this->service([$this->json(200, ['numTicket' => 'NEW'])])->request('202610');

        $this->assertSame('NEW', $r['request']->ticket);
        $this->assertSame(SireRequest::STATUS_FAILED, $old->status);
        $this->assertSame(SireRequest::STATUS_FAILED, $noTicket->status);
    }

    public function testRefreshOfExpiredRecordClosesItWithoutCallingSunat()
    {
        $this->store = new InMemorySireStore();
        $old = $this->store->save(new SireRequest(['book' => '140000', 'period' => '202610', 'ticket' => 'OLD', 'status' => 'processing', 'requested_at' => Carbon::now()->subHours(30)]));

        $out = $this->service([])->refresh($old);

        $this->assertSame(SireRequest::STATUS_FAILED, $out['request']->status);
        $this->assertSame(1, $this->mock->count()); // only the token response was queued, never consumed
    }

    public function testDoubleRequestYieldsSingleTicket()
    {
        $svc = $this->service([$this->json(200, ['numTicket' => 'T1'])]);

        $first = $svc->request('202610');
        $second = $svc->request('202610'); // would need a second export response: none queued

        $this->assertSame('T1', $first['request']->ticket);
        $this->assertSame($first['request'], $second['request']);
        $this->assertCount(1, $this->store->all);
    }

    public function testPlaceholderWithoutTicketBlocksConcurrentRequest()
    {
        $this->store = new InMemorySireStore();
        $this->store->save(new SireRequest(['book' => '140000', 'period' => '202610', 'status' => 'requested', 'requested_at' => Carbon::now()]));

        $r = $this->service([])->request('202610');

        $this->assertNull($r['request']->ticket);
        $this->assertCount(1, $this->store->all);
    }

    public function testUnrecognizedLayoutFailsWithoutReconciling()
    {
        $garbage = "hola mundo\notra linea sin sentido\nmas texto|raro|aqui\n";
        $svc = $this->service([$this->json(200, ['numTicket' => 'T1']), $this->ready(), new Response(200, [], $this->zip($garbage))]);
        $record = $svc->request('202610')['request'];

        $out = $svc->refresh($record);

        $this->assertSame(SireRequest::STATUS_FAILED, $out['request']->status);
        $this->assertSame('No se reconoce el formato de la propuesta de SUNAT; no se compararon los datos.', $out['request']->status_detail);
        $this->assertNull($out['request']->summary);
    }

    public function testParserGateAndIncompleteHeader()
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No se reconoce el formato');
        P::parseText("Serie,Total,Otra\nabc,def,ghi\n");
    }

    public function testParserNumberFormats()
    {
        $txt = "Tipo CP/Doc.|Serie del CDP|Nro CP o Doc. Nro Inicial (Rango)|Total CP\n01|F001|1|1,234.56\n01|F001|2|1.234,56\n01|F001|3|12,5\n";
        $rows = P::parseText($txt)['rows'];

        $this->assertSame([1234.56, 1234.56, 12.5], array_column($rows, 'total'));
    }

    public function testUnreadableZipEntryThrowsFriendlyException()
    {
        if (!method_exists(\ZipArchive::class, 'setEncryptionName')) {
            $this->markTestSkipped('ZipArchive encryption unavailable');
        }
        $path = tempnam(sys_get_temp_dir(), 'ez');
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::OVERWRITE);
        $zip->addFromString('p.txt', "Tipo CP/Doc.|Serie del CDP|Nro CP o Doc. Nro Inicial (Rango)|Total CP\n01|F001|1|10.00\n");
        $zip->setEncryptionName('p.txt', \ZipArchive::EM_AES_256, 'secret');
        $zip->close();
        $bin = file_get_contents($path);
        unlink($path);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No se pudo leer el archivo de la propuesta de SUNAT.');
        P::parseZip($bin);
    }

    public function testTicketLookupWalksSeveralPages()
    {
        $other = function ($n) {
            return ['numTicket' => 'X'.$n, 'codEstadoProceso' => '06'];
        };
        $svc = $this->service([
            $this->json(200, ['paginacion' => ['page' => 1, 'perPage' => 20, 'totalRegistros' => 25], 'registros' => array_map($other, range(1, 20))]),
            $this->json(200, ['paginacion' => ['page' => 2, 'perPage' => 20, 'totalRegistros' => 25], 'registros' => [$other(21), ['numTicket' => 'T1', 'codEstadoProceso' => '05']]]),
        ]);
        $client = (new \ReflectionProperty($svc, 'client'));
        $client->setAccessible(true);

        $status = $client->getValue($svc)->ticketStatus('T1', '202610');

        $this->assertTrue($status['found']);
        $this->assertSame('processing', $status['state']);
        $this->assertSame(0, $this->mock->count());
    }

    public function testTicketLookupStopsAtTotalRegistros()
    {
        $svc = $this->service([
            $this->json(200, ['paginacion' => ['totalRegistros' => 3], 'registros' => [['numTicket' => 'A'], ['numTicket' => 'B'], ['numTicket' => 'C']]]),
        ]);
        $client = (new \ReflectionProperty($svc, 'client'));
        $client->setAccessible(true);

        $this->assertFalse($client->getValue($svc)->ticketStatus('T1', '202610')['found']);
    }
}

class InMemorySireStore implements SireRequestStore
{
    /** @var SireRequest[] */
    public $all = [];

    public function atomic(string $period, callable $fn)
    {
        return $fn();
    }

    public function openRecords(string $period): array
    {
        $open = array_filter($this->all, function ($r) use ($period) {
            return $r->period === $period && $r->isOpen();
        });

        return array_reverse(array_values($open));
    }

    public function save(SireRequest $record): SireRequest
    {
        if (!$record->id) {
            $record->id = count($this->all) + 1;
            $this->all[] = $record;
        }

        return $record;
    }
}
