<?php

namespace App\CoreFacturalo\WS\Cpe;

use App\CoreFacturalo\WS\Gre\Exceptions\GreApiException;
use App\CoreFacturalo\WS\Gre\Exceptions\GreConfigurationException;
use App\CoreFacturalo\WS\Gre\GreClient;
use App\Models\Tenant\Company;

/**
 * Client for SUNAT's "Consulta Integrada de Validez de Comprobantes de Pago" API (manual v2.0).
 *
 *  - Token: POST {token_url}/clientesextranet/{client_id}/oauth2/token/ with grant_type=client_credentials,
 *    scope, client_id, client_secret (no SOL user/password).
 *  - Validate: POST {api_url}/contribuyentes/{RUC consultante}/validarcomprobante with a JSON body.
 *
 * Reuses the cache/401-retry/typed-exception machinery of GreClient. The token cache key embeds the scope and
 * a hash of the secret, so these tokens never collide with GRE/SIRE ones.
 */
class CpeValidationClient extends GreClient
{
    protected $apiLabel = 'de consulta de validez de comprobantes';

    protected $cachePrefix = 'cpe';

    public function __construct(array $settings, $http = null, $cache = null)
    {
        $settings += [
            'scope' => 'https://api.sunat.gob.pe/v1/contribuyente/contribuyentes',
            'timeout' => 20,
        ];

        parent::__construct($settings, $http, $cache);
    }

    /**
     * Builds the client settings for a company. Only the dedicated cpe_client_id/secret are used: this API
     * has its own credentials and a different OAuth flow, so GRE/SIRE ones would be rejected by SUNAT.
     *
     * @throws GreConfigurationException
     */
    public static function settingsForCompany(Company $company)
    {
        $clientId = trim((string) $company->cpe_client_id);
        $secret = $company->hasCpeClientSecret() ? $company->getCpeClientSecret() : null;

        $missing = [];
        if ($clientId === '') {
            $missing[] = 'client_id';
        }
        if (!$secret) {
            $missing[] = 'client_secret';
        }
        if ($missing) {
            throw new GreConfigurationException(
                'Faltan las credenciales de la consulta de validez de comprobantes ('.implode(', ', $missing).'). '
                .'Configúrelas en Configuración > Empresa > Credenciales API SUNAT (Consulta de validez de comprobantes).'
            );
        }

        return [
            'ruc' => $company->number,
            'token_url' => config('cpe_validation.token_url'),
            'api_url' => config('cpe_validation.api_url'),
            'scope' => config('cpe_validation.scope'),
            'connect_timeout' => config('cpe_validation.connect_timeout'),
            'timeout' => config('cpe_validation.timeout'),
            'client_id' => $clientId,
            'client_secret' => $secret,
        ];
    }

    protected function requiredSettings()
    {
        return ['ruc', 'token_url', 'api_url', 'client_id', 'client_secret'];
    }

    protected function tokenUrl()
    {
        return rtrim($this->settings['token_url'], '/').'/clientesextranet/'.$this->settings['client_id'].'/oauth2/token/';
    }

    protected function tokenRequestOptions()
    {
        return [
            'form_params' => [
                'grant_type' => 'client_credentials',
                'scope' => $this->settings['scope'],
                'client_id' => $this->settings['client_id'],
                'client_secret' => $this->settings['client_secret'],
            ],
        ];
    }

    /**
     * Validates one comprobante.
     *
     * @param array $payload numRuc, codComp, numeroSerie, numero, fechaEmision (dd/mm/yyyy), monto (electronic only)
     * @return array the "data" node: estadoCp, estadoRuc, condDomiRuc, Observaciones[]
     * @throws GreApiException when SUNAT answers an error status or success=false
     */
    public function validate(array $payload)
    {
        $url = rtrim($this->settings['api_url'], '/').'/contribuyentes/'.$this->settings['ruc'].'/validarcomprobante';
        $body = $this->authorizedRequest('POST', $url, ['json' => $payload]);

        if (empty($body['success']) || !isset($body['data']) || !is_array($body['data'])) {
            $message = $body['message'] ?? 'SUNAT no devolvió el resultado de la validación.';
            $code = isset($body['errorCode']) ? (string) $body['errorCode'] : null;
            throw new GreApiException($message, 200, $code, $message);
        }

        return $body['data'];
    }

    protected function throwApiError($status, array $body)
    {
        // This API answers {success, message, errorCode} instead of {cod, msg}.
        if (isset($body['errorCode']) && !isset($body['cod'])) {
            $body['cod'] = $body['errorCode'];
        }

        parent::throwApiError($status, $body);
    }
}
