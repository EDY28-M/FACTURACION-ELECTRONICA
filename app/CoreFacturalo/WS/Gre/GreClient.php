<?php

namespace App\CoreFacturalo\WS\Gre;

use App\CoreFacturalo\WS\Gre\Exceptions\GreApiException;
use App\CoreFacturalo\WS\Gre\Exceptions\GreAuthException;
use App\CoreFacturalo\WS\Gre\Exceptions\GreConfigurationException;
use App\CoreFacturalo\WS\Gre\Exceptions\GreConnectionException;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;

/**
 * REST client for the SUNAT GRE 2.0 API (OAuth2 password grant + comprobantes).
 *
 * Settings: ruc, token_url, api_url, client_id, client_secret, username,
 * password (SOL user, already prefixed with the RUC), scope, connect_timeout,
 * timeout.
 */
class GreClient
{
    /** @var array */
    protected $settings;

    /** @var ClientInterface */
    private $http;

    /** @var CacheRepository */
    private $cache;

    /** Human label used in error messages (subclasses such as SireClient override it). */
    protected $apiLabel = 'GRE';

    /** Token cache namespace; the cache key also embeds the scope so tokens of different APIs never collide. */
    protected $cachePrefix = 'gre';

    public function __construct(array $settings, ClientInterface $http = null, CacheRepository $cache = null)
    {
        $missing = [];
        foreach ($this->requiredSettings() as $key) {
            if (empty($settings[$key])) {
                $missing[] = $key;
            }
        }
        if ($missing) {
            throw new GreConfigurationException(
                'Configuración '.$this->apiLabel.' incompleta: falta '.implode(', ', $missing).'.'
            );
        }

        $this->settings = $settings + [
            'scope' => 'https://api-cpe.sunat.gob.pe',
            'connect_timeout' => 10,
            'timeout' => 30,
        ];

        $this->http = $http ?: new Client();
        $this->cache = $cache ?: Cache::store();
    }

    /**
     * Returns a valid access token, reusing the cached one until it expires.
     */
    public function getToken($forceRefresh = false)
    {
        $key = $this->tokenCacheKey();

        if ($forceRefresh) {
            $this->cache->forget($key);
        } else {
            $cached = $this->cache->get($key);
            if ($cached) {
                return $cached;
            }
        }

        $response = $this->request('POST', $this->tokenUrl(), $this->tokenRequestOptions());

        $status = $response->getStatusCode();
        $body = $this->decode((string) $response->getBody());

        if ($status < 200 || $status >= 300 || empty($body['access_token'])) {
            $message = $body['error_description'] ?? $body['msg'] ?? $body['error'] ?? 'No se pudo obtener el token de SUNAT.';
            throw new GreAuthException(
                'SUNAT rechazó las credenciales de la API '.$this->apiLabel.': '.$message,
                $status,
                $body['cod'] ?? ($body['error'] ?? null),
                $message
            );
        }

        $ttl = max(((int) ($body['expires_in'] ?? 3600)) - 60, 1);
        $this->cache->put($key, $body['access_token'], $ttl);

        return $body['access_token'];
    }

    /** Settings that must be present; subclasses with a different OAuth flow override it. */
    protected function requiredSettings()
    {
        return ['ruc', 'token_url', 'api_url', 'client_id', 'client_secret', 'username', 'password'];
    }

    /** Token endpoint (password grant against the "clientessol" realm by default). */
    protected function tokenUrl()
    {
        return rtrim($this->settings['token_url'], '/').'/clientessol/'.$this->settings['client_id'].'/oauth2/token/';
    }

    /** Guzzle options of the token request. */
    protected function tokenRequestOptions()
    {
        return [
            'form_params' => [
                'grant_type' => 'password',
                'scope' => $this->settings['scope'],
                'client_id' => $this->settings['client_id'],
                'client_secret' => $this->settings['client_secret'],
                'username' => $this->settings['username'],
                'password' => $this->settings['password'],
            ],
        ];
    }

    /**
     * Sends a zipped signed XML. Returns ['ticket' => ..., 'received_at' => ...].
     *
     * @param string $filenameBase {RUC}-09-{serie}-{numero}, without extension
     * @param string $zipContent   binary zip containing exactly one xml named {filenameBase}.xml
     */
    public function send($filenameBase, $zipContent)
    {
        $payload = [
            'archivo' => [
                'nomArchivo' => $filenameBase.'.zip',
                'arcGreZip' => base64_encode($zipContent),
                'hashZip' => hash('sha256', $zipContent),
            ],
        ];

        $url = rtrim($this->settings['api_url'], '/').'/contribuyente/gem/comprobantes/'.$filenameBase;
        $body = $this->authorizedRequest('POST', $url, ['json' => $payload]);

        if (empty($body['numTicket'])) {
            throw new GreApiException('SUNAT no devolvió un número de ticket.', 200, null, null);
        }

        return [
            'ticket' => (string) $body['numTicket'],
            'received_at' => $body['fecRecepcion'] ?? null,
        ];
    }

    /**
     * Queries a ticket and normalizes the answer.
     *
     * @return GreStatusResult
     */
    public function status($ticket)
    {
        $url = rtrim($this->settings['api_url'], '/').'/contribuyente/gem/comprobantes/envios/'.$ticket;
        $body = $this->authorizedRequest('GET', $url);

        $code = (string) ($body['codRespuesta'] ?? '');

        $result = new GreStatusResult();
        $result->code = $code;
        $result->raw = $body;
        $result->cdrGenerated = (string) ($body['indCdrGenerado'] ?? '0') === '1';

        if (!empty($body['arcCdr'])) {
            $zip = base64_decode($body['arcCdr'], true);
            $result->cdrZip = $zip === false ? null : $zip;
        }

        if ($code === '0') {
            $result->status = GreStatusResult::ACCEPTED;
        } elseif ($code === '99') {
            $result->status = GreStatusResult::REJECTED;
            $error = $body['error'] ?? [];
            $result->errorCode = isset($error['numError']) ? (string) $error['numError'] : null;
            $result->errorMessage = $error['desError'] ?? null;
        } elseif ($code === '98') {
            $result->status = GreStatusResult::PROCESSING;
        } else {
            throw new GreApiException(
                'Respuesta de estado no reconocida por SUNAT (codRespuesta: '.($code === '' ? 'vacío' : $code).').',
                200
            );
        }

        return $result;
    }

    /**
     * Executes an API call with the bearer token. Retries once with a fresh
     * token if SUNAT answers 401 (token revoked/expired earlier than cached).
     */
    protected function authorizedRequest($method, $url, array $options = [])
    {
        return $this->decode((string) $this->authorizedRaw($method, $url, $options)->getBody());
    }

    /**
     * Same as authorizedRequest() but returns the PSR-7 response (2xx only; other statuses throw),
     * so callers can read binary bodies such as ZIP downloads.
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    protected function authorizedRaw($method, $url, array $options = [])
    {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $token = $this->getToken($attempt > 0);
            $options['headers'] = ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];

            $response = $this->request($method, $url, $options);
            $status = $response->getStatusCode();

            if ($status === 401 && $attempt === 0) {
                continue;
            }

            if ($status >= 200 && $status < 300) {
                return $response;
            }

            $this->throwApiError($status, $this->decode((string) $response->getBody()));
        }
    }

    protected function throwApiError($status, array $body)
    {
        $code = isset($body['cod']) ? (string) $body['cod'] : null;
        $msg = $body['msg'] ?? ($body['message'] ?? null);
        $errors = [];
        foreach (($body['errors'] ?? []) as $error) {
            $errors[] = [
                // GRE answers {codError, desError}; the SIRE manual documents {cod, msg} (v32, section 5).
                'codError' => $error['codError'] ?? ($error['cod'] ?? null),
                'desError' => $error['desError'] ?? ($error['msg'] ?? null),
            ];
        }

        $text = 'SUNAT respondió con error HTTP '.$status
            .($code ? " (cod {$code})" : '')
            .($msg ? ': '.$msg : '.');

        if ($status === 401 || $status === 403) {
            throw new GreAuthException($text, $status, $code, $msg, $errors);
        }

        throw new GreApiException($text, $status, $code, $msg, $errors);
    }

    protected function request($method, $url, array $options)
    {
        $options += [
            'http_errors' => false,
            'connect_timeout' => $this->settings['connect_timeout'],
            'timeout' => $this->settings['timeout'],
        ];

        try {
            return $this->http->request($method, $url, $options);
        } catch (GuzzleException $e) {
            throw new GreConnectionException(
                'No se pudo conectar con SUNAT: '.$e->getMessage(),
                null,
                null,
                null,
                [],
                $e
            );
        }
    }

    protected function decode($raw)
    {
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    protected function tokenCacheKey()
    {
        return $this->cachePrefix.':token:'.$this->settings['ruc'].':'.$this->settings['client_id'].':'.md5($this->settings['scope']).':'.md5($this->settings['username'] ?? '').':'.md5($this->settings['client_secret'].($this->settings['password'] ?? ''));
    }
}
