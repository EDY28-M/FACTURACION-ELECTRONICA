<?php

namespace Modules\Services\Data;

use GuzzleHttp\Client;
use App\Models\System\Configuration;

class ServiceData
{
    const SLOT_PRIMARY = 'primary';
    const SLOT_SECONDARY = 'secondary';
    const DEFAULT_SECONDARY_URL = 'https://api.apiperu.pe';

    /**
     * Optional Guzzle handler, only meant to be set from tests.
     *
     * @var callable|null
     */
    protected static $httpHandler = null;

    public static function setHttpHandler(?callable $handler)
    {
        self::$httpHandler = $handler;
    }

    /**
     * Ordered list of usable external providers (the preferred one first).
     * Empty when nothing is configured.
     *
     * @return array<int, array{slot: string, label: string, driver: string, url: string, token: string}>
     */
    public static function providers()
    {
        $configuration = Configuration::first();

        // 'false' is the legacy "internal lookup" marker: treat it as no token.
        $storedToken = $configuration ? $configuration->token_apiruc : null;
        $primaryToken = ($storedToken != '' && $storedToken !== 'false') ? $storedToken : config('configuration.api_service_token');

        $primaryUrl = ($configuration && $configuration->url_apiruc != '') ? $configuration->url_apiruc : config('configuration.api_service_url');
        $secondaryToken = $configuration ? $configuration->secondary_token_apiruc : null;
        $secondaryUrl = ($configuration && $configuration->secondary_url_apiruc != '') ? $configuration->secondary_url_apiruc : self::DEFAULT_SECONDARY_URL;

        $providers = [];
        if (!empty($primaryUrl) && !empty($primaryToken) && $primaryToken !== 'false') {
            $providers[self::SLOT_PRIMARY] = [
                'slot' => self::SLOT_PRIMARY,
                'label' => 'Proveedor 1',
                'driver' => 'get_path',
                'url' => $primaryUrl,
                'token' => $primaryToken,
            ];
        }
        if (!empty($secondaryToken) && $secondaryToken !== 'false') {
            $providers[self::SLOT_SECONDARY] = [
                'slot' => self::SLOT_SECONDARY,
                'label' => 'Proveedor 2',
                'driver' => 'post_json',
                'url' => $secondaryUrl,
                'token' => $secondaryToken,
            ];
        }

        $first = ($configuration && $configuration->apiruc_primary === self::SLOT_SECONDARY) ? self::SLOT_SECONDARY : self::SLOT_PRIMARY;
        uksort($providers, function ($a, $b) use ($first) {
            return ($a === $first ? 0 : 1) <=> ($b === $first ? 0 : 1);
        });

        return array_values($providers);
    }

    /**
     * Credentials of the preferred provider, or null when nothing is configured.
     *
     * @return array{url: string, token: string}|null
     */
    public static function credentials()
    {
        $providers = self::providers();

        return $providers ? ['url' => $providers[0]['url'], 'token' => $providers[0]['token']] : null;
    }

    public static function isConfigured()
    {
        return count(self::providers()) > 0;
    }

    public static function service($type, $number)
    {
        if (!in_array($type, ['ruc', 'dni'], true)) {
            return [
                'success' => false,
                'message' => 'Tipo de consulta inválido',
            ];
        }

        $number = (string) $number;
        if (!ctype_digit($number) || !in_array(strlen($number), [8, 11], true)) {
            return [
                'success' => false,
                'message' => 'Número de documento inválido',
            ];
        }

        $providers = self::providers();
        if (!$providers) {
            return [
                'success' => false,
                'message' => 'Servicio de consulta no configurado',
            ];
        }

        $failures = [];
        foreach ($providers as $provider) {
            $response = self::query($provider, $type, $number);
            if (($response['success'] ?? false) === true) {
                return $response;
            }
            $failures[$provider['slot']] = $response;
        }

        if (count($providers) === 1) {
            return reset($failures);
        }

        // Label by slot (Proveedor 1 / Proveedor 2), not by the order they were tried.
        $parts = [];
        foreach ([self::SLOT_PRIMARY => 'Proveedor 1', self::SLOT_SECONDARY => 'Proveedor 2'] as $slot => $label) {
            if (isset($failures[$slot])) {
                $parts[] = $label.': '.($failures[$slot]['message'] ?? 'Error desconocido');
            }
        }

        return [
            'success' => false,
            'message' => implode(' · ', $parts),
        ];
    }

    protected static function query(array $provider, $type, $number)
    {
        $options = [
            'base_uri' => $provider['url'],
            'verify' => false,
            'timeout' => 10,
        ];
        if (self::$httpHandler) {
            $options['handler'] = self::$httpHandler;
        }
        $client = new Client($options);

        $parameters = [
            'http_errors' => false,
            'connect_timeout' => 5,
            'headers' => [
                'Authorization' => 'Bearer '.$provider['token'],
                'Accept' => 'application/json',
            ],
        ];

        try {
            if ($provider['driver'] === 'post_json') {
                $parameters['json'] = [$type => $number];
                $res = $client->request('POST', '/'.$type, $parameters);
            } else {
                $res = $client->request('GET', '/api/'.$type.'/'.$number, $parameters);
            }
            $response = json_decode($res->getBody()->getContents(), true);

            if (!is_array($response)) {
                return [
                    'success' => false,
                    'message' => 'Respuesta inválida del servicio externo',
                ];
            }

            return $response;
        } catch (\Throwable $th) {
            report($th);

            return [
                'success' => false,
                'message' => 'Error al consultar el servicio externo',
            ];
        }
    }
}
