<?php

/*
|--------------------------------------------------------------------------
| SUNAT GRE 2.0 (Guia de Remision Electronica) REST API
|--------------------------------------------------------------------------
|
| Production endpoints are the official SUNAT hosts. SUNAT does NOT provide
| an official beta/sandbox for the GRE REST API. When a company runs in demo
| mode (soap_type_id = '01') the guide is generated and signed but it is only
| sent if the "test" block below is fully configured.
|
| A third-party sandbox exists (for example Nubefact's
| https://gre-test.nubefact.com/v1); it is NOT operated by SUNAT and the
| credentials must be supplied through the environment. No credentials are
| hardcoded here.
|
*/

return [
    'production' => [
        'token_url' => env('GRE_PRODUCTION_TOKEN_URL', 'https://api-seguridad.sunat.gob.pe/v1'),
        'api_url' => env('GRE_PRODUCTION_API_URL', 'https://api-cpe.sunat.gob.pe/v1'),
    ],

    'test' => [
        'token_url' => env('GRE_TEST_TOKEN_URL', ''),
        'api_url' => env('GRE_TEST_API_URL', ''),
        'client_id' => env('GRE_TEST_CLIENT_ID', ''),
        'client_secret' => env('GRE_TEST_CLIENT_SECRET', ''),
        'username' => env('GRE_TEST_USERNAME', ''),
        'password' => env('GRE_TEST_PASSWORD', ''),
    ],

    'scope' => env('GRE_SCOPE', 'https://api-cpe.sunat.gob.pe'),

    'connect_timeout' => (int) env('GRE_CONNECT_TIMEOUT', 10),
    'timeout' => (int) env('GRE_TIMEOUT', 30),
];
