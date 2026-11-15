<?php

namespace Tests\Unit;

use App\CoreFacturalo\Services\Sire\SireReconciliationService;
use App\CoreFacturalo\WS\Gre\Exceptions\GreApiException;
use App\CoreFacturalo\WS\Gre\Exceptions\GreAuthException;
use App\CoreFacturalo\WS\Gre\Exceptions\GreConfigurationException;
use App\CoreFacturalo\WS\Gre\Exceptions\GreConnectionException;
use App\CoreFacturalo\WS\Gre\GreClient;
use App\CoreFacturalo\WS\Sire\SireClient;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use PHPUnit\Framework\TestCase;

/**
 * Every HTTP exchange is served by a Guzzle MockHandler: no real call to SUNAT is possible.
 */
class SireClientTest extends TestCase
{
    private $history = [];

    private $settings = [
        'ruc' => '20123456789',
        'token_url' => 'https://token.test/v1',
        'api_url' => 'https://sire.test/v1/contribuyente/migeigv/libros',
        'client_id' => 'cid',
        'client_secret' => 'secret',
        'username' => '20123456789USER',
        'password' => 'pass',
    ];

    private function client(array $responses, Repository $cache = null)
    {
        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $this->history = [];
        $stack->push(Middleware::history($this->history));

        return new SireClient($this->settings, new Client(['handler' => $stack]), $cache ?: new Repository(new ArrayStore()));
    }

    private function json($status, array $body)
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($body));
    }

    private function token($value = 'TOKEN1')
    {
        return $this->json(200, ['access_token' => $value, 'token_type' => 'JWT', 'expires_in' => 3600]);
    }

    private function zip(string $name, string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'sirezip');
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::OVERWRITE);
        $zip->addFromString($name, $content);
        $zip->close();
        $binary = file_get_contents($path);
        unlink($path);

        return $binary;
    }

    public function testTokenRequestUsesSireScopeAndPasswordGrant()
    {
        $client = $this->client([$this->token()]);

        $this->assertSame('TOKEN1', $client->getToken());

        $request = $this->history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('https://token.test/v1/clientessol/cid/oauth2/token/', (string) $request->getUri());
        parse_str((string) $request->getBody(), $form);
        $this->assertSame('password', $form['grant_type']);
        $this->assertSame('https://api-sire.sunat.gob.pe', $form['scope']);
        $this->assertSame('20123456789USER', $form['username']);
        $this->assertSame('pass', $form['password']);
    }

    public function testTokenCacheKeyIncludesScopeSoGreAndSireDoNotCollide()
    {
        $cache = new Repository(new ArrayStore());

        $sire = $this->client([$this->token('SIRE-TOKEN')], $cache);
        $this->assertSame('SIRE-TOKEN', $sire->getToken());

        // Same credentials, GRE scope and client: must NOT reuse the SIRE token.
        $greMock = new MockHandler([$this->token('GRE-TOKEN')]);
        $gre = new GreClient($this->settings + ['scope' => 'https://api-cpe.sunat.gob.pe'], new Client(['handler' => HandlerStack::create($greMock)]), $cache);
        $this->assertSame('GRE-TOKEN', $gre->getToken());

        // Both stay cached independently.
        $this->assertSame('SIRE-TOKEN', $this->client([], $cache)->getToken());
        $this->assertSame('GRE-TOKEN', $gre->getToken());
    }

    public function testTokenCacheKeyChangesWithPassword()
    {
        $cache = new Repository(new ArrayStore());
        $this->client([$this->token('A')], $cache)->getToken();

        $mock = new MockHandler([$this->token('B')]);
        $other = new SireClient(['password' => 'changed'] + $this->settings, new Client(['handler' => HandlerStack::create($mock)]), $cache);
        $this->assertSame('B', $other->getToken());
    }

    public function testExportStoresTicketFromNumTicket()
    {
        $client = $this->client([$this->token(), $this->json(200, ['numTicket' => '202600000123'])]);

        $this->assertSame('202600000123', $client->requestProposalExport('202610'));

        $request = $this->history[1]['request'];
        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('/v1/contribuyente/migeigv/libros/rvie/propuesta/web/propuesta/202610/exportapropuesta', $request->getUri()->getPath());
        $this->assertSame('codTipoArchivo=0', $request->getUri()->getQuery());
        $this->assertSame('Bearer TOKEN1', $request->getHeaderLine('Authorization'));
    }

    public function testExportWithoutTicketThrows()
    {
        $client = $this->client([$this->token(), $this->json(200, [])]);
        $this->expectException(GreApiException::class);
        $client->requestProposalExport('202610');
    }

    public function testInvalidPeriodIsRejectedBeforeAnyRequest()
    {
        $client = $this->client([]);
        try {
            $client->requestProposalExport('2026/10');
            $this->fail('expected exception');
        } catch (GreConfigurationException $e) {
            $this->assertCount(0, $this->history);
        }
    }

    public function testStatusProcessingStaysProcessing()
    {
        $client = $this->client([$this->token(), $this->json(200, ['paginacion' => ['page' => 1], 'registros' => [
            ['numTicket' => '202600000123', 'codEstadoProceso' => '05', 'desEstadoProceso' => 'En proceso', 'archivoReporte' => []],
        ]])]);

        $status = $client->ticketStatus('202600000123', '202610');

        $this->assertSame('processing', $status['state']);
        $request = $this->history[1]['request'];
        $this->assertSame('/v1/contribuyente/migeigv/libros/rvierce/gestionprocesosmasivos/web/masivo/consultaestadotickets', $request->getUri()->getPath());
        parse_str($request->getUri()->getQuery(), $q);
        $this->assertSame(['perIni' => '202610', 'perFin' => '202610', 'page' => '1', 'perPage' => '20', 'numTicket' => '202600000123', 'codLibro' => '140000', 'codOrigenEnvio' => '2'], $q);
    }

    public function testStatusTicketNotListedYetIsProcessing()
    {
        $client = $this->client([$this->token(), $this->json(200, ['registros' => []])]);

        $this->assertSame('processing', $client->ticketStatus('202600000123', '202610')['state']);
    }

    public function testStatusWithErrorsIsFailed()
    {
        $client = $this->client([$this->token(), $this->json(200, ['registros' => [
            ['numTicket' => 'T1', 'codEstadoProceso' => '03', 'desEstadoProceso' => 'Procesado con errores'],
        ]])]);

        $this->assertSame('failed', $client->ticketStatus('T1', '202610')['state']);
    }

    public function testStatusFinishedExposesFiles()
    {
        $client = $this->client([$this->token(), $this->json(200, ['registros' => [
            ['numTicket' => 'T1', 'perTributario' => '202610', 'codProceso' => '10', 'codEstadoProceso' => '06', 'desEstadoProceso' => 'Terminado',
                'archivoReporte' => [['codTipoAchivoReporte' => '01', 'nomArchivoReporte' => '20123456789-propuesta.zip', 'nomArchivoContenido' => 'x.txt']]],
        ]])]);

        $status = $client->ticketStatus('T1', '202610');

        $this->assertSame('ready', $status['state']);
        $this->assertSame([['type' => '01', 'name' => '20123456789-propuesta.zip', 'content' => 'x.txt']], $status['files']);
        $this->assertSame('10', $status['process']);
    }

    public function testDownloadReportReturnsZipAndSendsDocumentedParameters()
    {
        $zip = $this->zip('propuesta.txt', 'x');
        $client = $this->client([$this->token(), new Response(200, ['Content-Type' => 'application/zip'], $zip)]);

        $content = $client->downloadReport(['type' => '01', 'name' => 'a.zip', 'content' => null], 'T1', '202610', '10');

        $this->assertSame($zip, $content);
        $request = $this->history[1]['request'];
        $this->assertSame('/v1/contribuyente/migeigv/libros/rvierce/gestionprocesosmasivos/web/masivo/archivoreporte', $request->getUri()->getPath());
        parse_str($request->getUri()->getQuery(), $q);
        $this->assertSame(['nomArchivoReporte' => 'a.zip', 'perTributario' => '202610', 'codProceso' => '10', 'numTicket' => 'T1', 'codLibro' => '140000', 'codTipoArchivoReporte' => '01'], $q);
    }

    public function testDownloadOfNonZipThrows()
    {
        $client = $this->client([$this->token(), new Response(200, [], '{"oops":true}')]);
        $this->expectException(GreApiException::class);
        $client->downloadReport(['type' => null, 'name' => 'a.zip'], 'T1', '202610');
    }

    public function testFullFlowDownloadsParsesAndReconciles()
    {
        $txt = "Ruc,Periodo,CAR SUNAT,Fecha de emisión,Tipo CP/Doc.,Serie del CDP,Nro CP o Doc. Nro Inicial (Rango),Nro Final (Rango),Total CP\n"
            ."20123456789,202610,,05/10/2026,01,F001,25,,118.00\n"
            ."20123456789,202610,,06/10/2026,01,F001,26,,59.00\n";
        $client = $this->client([
            $this->token(),
            $this->json(200, ['registros' => [['numTicket' => 'T1', 'codProceso' => '10', 'codEstadoProceso' => '06', 'archivoReporte' => [['nomArchivoReporte' => 'p.zip']]]]]),
            new Response(200, [], $this->zip('p.txt', $txt)),
        ]);

        $status = $client->ticketStatus('T1', '202610');
        $parsed = SireReconciliationService::downloadAndParse($client, $status['files'][0], 'T1', '202610', $status['process']);
        $system = ['rows' => [
            ['type' => '01', 'series' => 'F001', 'number' => '25', 'date' => '2026-10-05', 'total' => 118.0, 'state' => '05'],
            ['type' => '01', 'series' => 'F001', 'number' => '27', 'date' => '2026-10-07', 'total' => 10.0, 'state' => '05'],
        ], 'excluded' => []];
        $summary = SireReconciliationService::buildSummary('202610', $parsed['rows'], $parsed, $system);

        $this->assertSame(1, $summary['matched']);
        $this->assertSame(1, $summary['solo_en_sunat_count']);
        $this->assertSame('26', $summary['solo_en_sunat'][0]['number']);
        $this->assertSame(1, $summary['solo_en_sistema_count']);
        $this->assertSame(177.0, $summary['sunat']['total']);
        $this->assertSame('202610', $summary['period']);
        $this->assertTrue($summary['parser']['header']);
    }

    public function testApiValidationErrorUsesCodMsgShapeAndIsMapped()
    {
        $client = $this->client([$this->token(), $this->json(422, [
            'cod' => '422', 'msg' => 'Unprocessable Entity',
            'errors' => [['cod' => '1070', 'msg' => 'No se ha encontrado información de comprobantes de pago en Periodo Seleccionado']],
        ])]);

        try {
            $client->requestProposalExport('202610');
            $this->fail('expected exception');
        } catch (GreApiException $e) {
            $this->assertSame(422, $e->getHttpStatus());
            $this->assertSame('1070', $e->getErrors()[0]['codError']);
            $this->assertStringContainsString('no encontró comprobantes', SireReconciliationService::friendlyError($e));
        }
    }

    public function testFriendlyErrorMapping()
    {
        $this->assertStringContainsString('no está disponible', SireReconciliationService::friendlyError(new GreApiException('x', 503)));
        $this->assertStringContainsString('demasiadas', SireReconciliationService::friendlyError(new GreApiException('x', 429)));
        $this->assertStringContainsString('rechazó las credenciales', SireReconciliationService::friendlyError(new GreAuthException('x', 401)));
        $this->assertStringContainsString('No se pudo conectar', SireReconciliationService::friendlyError(new GreConnectionException('boom secret')));
        $this->assertStringNotContainsString('secret', SireReconciliationService::friendlyError(new GreConnectionException('boom secret')));
    }

    public function testNetworkFailureBecomesConnectionException()
    {
        $client = $this->client([new ConnectException('timeout', new Request('POST', 'https://token.test'))]);

        $this->expectException(GreConnectionException::class);
        $client->requestProposalExport('202610');
    }

    public function testRejectedTokenThrowsAuthException()
    {
        $client = $this->client([$this->json(401, ['error' => 'invalid_client', 'error_description' => 'Bad client'])]);

        $this->expectException(GreAuthException::class);
        $client->requestProposalExport('202610');
    }

    public function testMissingCredentialsThrowClearException()
    {
        $this->expectException(GreConfigurationException::class);
        $this->expectExceptionMessage('Configuración SIRE incompleta');
        new SireClient(['ruc' => '20123456789']);
    }
}
