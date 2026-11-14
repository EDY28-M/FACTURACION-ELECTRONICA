<?php

namespace Tests\Unit;

use App\CoreFacturalo\WS\Gre\Exceptions\GreApiException;
use App\CoreFacturalo\WS\Gre\Exceptions\GreAuthException;
use App\CoreFacturalo\WS\Gre\Exceptions\GreConfigurationException;
use App\CoreFacturalo\WS\Gre\GreClient;
use App\CoreFacturalo\WS\Gre\GreStatusResult;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use PHPUnit\Framework\TestCase;

class GreClientTest extends TestCase
{
    private $history = [];

    private function client(array $responses, Repository $cache = null)
    {
        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $this->history = [];
        $stack->push(Middleware::history($this->history));

        return new GreClient([
            'ruc' => '20123456789',
            'token_url' => 'https://token.test/v1',
            'api_url' => 'https://api.test/v1',
            'client_id' => 'cid',
            'client_secret' => 'secret',
            'username' => '20123456789USER',
            'password' => 'pass',
        ], new Client(['handler' => $stack]), $cache ?: new Repository(new ArrayStore()));
    }

    private function json($status, array $body)
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($body));
    }

    private function token()
    {
        return $this->json(200, ['access_token' => 'TOKEN1', 'token_type' => 'JWT', 'expires_in' => 3600]);
    }

    public function testMissingConfigurationThrows()
    {
        $this->expectException(GreConfigurationException::class);
        new GreClient(['ruc' => '20123456789']);
    }

    public function testTokenIsRequestedWithPasswordGrantAndCached()
    {
        $client = $this->client([$this->token()]);

        $this->assertSame('TOKEN1', $client->getToken());
        $this->assertSame('TOKEN1', $client->getToken()); // cached: no second HTTP call queued

        $this->assertCount(1, $this->history);
        $request = $this->history[0]['request'];
        $this->assertSame('https://token.test/v1/clientessol/cid/oauth2/token/', (string) $request->getUri());
        parse_str((string) $request->getBody(), $form);
        $this->assertSame('password', $form['grant_type']);
        $this->assertSame('20123456789USER', $form['username']);
        $this->assertSame('https://api-cpe.sunat.gob.pe', $form['scope']);
    }

    public function testTokenCacheKeyChangesWhenCredentialsChange()
    {
        $cache = new Repository(new ArrayStore());
        $first = $this->client([$this->token()], $cache);
        $this->assertSame('TOKEN1', $first->getToken());

        $mock = new MockHandler([$this->json(200, ['access_token' => 'TOKEN-NEW', 'expires_in' => 3600])]);
        $changed = new GreClient([
            'ruc' => '20123456789', 'token_url' => 'https://token.test/v1', 'api_url' => 'https://api.test/v1',
            'client_id' => 'cid', 'client_secret' => 'OTHER', 'username' => '20123456789USER', 'password' => 'pass',
        ], new Client(['handler' => HandlerStack::create($mock)]), $cache);

        $this->assertSame('TOKEN-NEW', $changed->getToken());
    }

    public function testInvalidCredentialsThrowAuthException()
    {
        $client = $this->client([$this->json(401, ['error' => 'invalid_client', 'error_description' => 'Client credentials are invalid'])]);

        try {
            $client->getToken();
            $this->fail('Expected GreAuthException');
        } catch (GreAuthException $e) {
            $this->assertSame(401, $e->getHttpStatus());
            $this->assertStringContainsString('Client credentials are invalid', $e->getMessage());
        }
    }

    public function testSendReturnsTicketAndBuildsPayload()
    {
        $client = $this->client([
            $this->token(),
            $this->json(200, ['numTicket' => '123456789012', 'fecRecepcion' => '2026-11-13T10:00:00.000']),
        ]);

        $zip = 'FAKE-ZIP-BYTES';
        $result = $client->send('20123456789-09-T001-1', $zip);

        $this->assertSame('123456789012', $result['ticket']);

        $request = $this->history[1]['request'];
        $this->assertSame('https://api.test/v1/contribuyente/gem/comprobantes/20123456789-09-T001-1', (string) $request->getUri());
        $this->assertSame('Bearer TOKEN1', $request->getHeaderLine('Authorization'));
        $body = json_decode((string) $request->getBody(), true);
        $this->assertSame('20123456789-09-T001-1.zip', $body['archivo']['nomArchivo']);
        $this->assertSame(base64_encode($zip), $body['archivo']['arcGreZip']);
        $this->assertSame(hash('sha256', $zip), $body['archivo']['hashZip']);
    }

    public function testValidationErrorIsMappedWithErrorList()
    {
        $client = $this->client([
            $this->token(),
            $this->json(422, [
                'cod' => '422',
                'msg' => 'Error de validación',
                'errors' => [['codError' => '2559', 'desError' => 'El dato ingresado como atributo @schemeID es incorrecto']],
            ]),
        ]);

        try {
            $client->send('20123456789-09-T001-1', 'zip');
            $this->fail('Expected GreApiException');
        } catch (GreApiException $e) {
            $this->assertTrue($e->isValidationError());
            $this->assertSame(422, $e->getHttpStatus());
            $this->assertSame('422', $e->getSunatCode());
            $this->assertSame('2559', $e->getErrors()[0]['codError']);
            $this->assertStringContainsString('2559', $e->getDetailedMessage());
        }
    }

    public function testExpiredApiTokenIsRefreshedOnce()
    {
        $client = $this->client([
            $this->token(),
            $this->json(401, ['cod' => '401', 'msg' => 'Unauthorized']),
            $this->json(200, ['access_token' => 'TOKEN2', 'expires_in' => 3600]),
            $this->json(200, ['numTicket' => '999']),
        ]);

        $this->assertSame('999', $client->send('20123456789-09-T001-2', 'zip')['ticket']);
        $this->assertSame('Bearer TOKEN2', $this->history[3]['request']->getHeaderLine('Authorization'));
    }

    public function testStatusProcessing()
    {
        $client = $this->client([$this->token(), $this->json(200, ['codRespuesta' => '98'])]);

        $result = $client->status('123');

        $this->assertTrue($result->isProcessing());
        $this->assertSame(
            'https://api.test/v1/contribuyente/gem/comprobantes/envios/123',
            (string) $this->history[1]['request']->getUri()
        );
    }

    public function testStatusAcceptedDecodesCdr()
    {
        $cdr = 'FAKE-CDR-ZIP';
        $client = $this->client([$this->token(), $this->json(200, [
            'codRespuesta' => '0',
            'indCdrGenerado' => '1',
            'arcCdr' => base64_encode($cdr),
        ])]);

        $result = $client->status('123');

        $this->assertSame(GreStatusResult::ACCEPTED, $result->status);
        $this->assertTrue($result->cdrGenerated);
        $this->assertSame($cdr, $result->cdrZip);
    }

    public function testStatusRejectedWithoutCdrKeepsError()
    {
        $client = $this->client([$this->token(), $this->json(200, [
            'codRespuesta' => '99',
            'indCdrGenerado' => '0',
            'error' => ['numError' => '1033', 'desError' => 'El comprobante fue registrado previamente'],
        ])]);

        $result = $client->status('123');

        $this->assertTrue($result->isRejected());
        $this->assertFalse($result->cdrGenerated);
        $this->assertNull($result->cdrZip);
        $this->assertSame('1033', $result->errorCode);
        $this->assertSame('El comprobante fue registrado previamente', $result->errorMessage);
    }
}
