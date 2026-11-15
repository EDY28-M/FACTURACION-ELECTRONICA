<?php

/*
|--------------------------------------------------------------------------
| SUNAT "Consulta Integrada de Validez de Comprobantes de Pago" REST API
|--------------------------------------------------------------------------
|
| Manual v2.0. Credentials (client_id / clave) are generated in SOL > Empresas > Comprobantes de pago >
| Consulta de Validez de Comprobantes de Pago > Credenciales de API SUNAT, and are independent of the
| GRE/SIRE ones. The token flow is client_credentials (no SOL user/password).
| Nothing here is a secret; the client id/secret are stored per company.
|
*/

return [
    'token_url' => env('CPE_VALIDATION_TOKEN_URL', 'https://api-seguridad.sunat.gob.pe/v1'),
    'api_url' => env('CPE_VALIDATION_API_URL', 'https://api.sunat.gob.pe/v1/contribuyente'),
    'scope' => env('CPE_VALIDATION_SCOPE', 'https://api.sunat.gob.pe/v1/contribuyente/contribuyentes'),

    'connect_timeout' => (int) env('CPE_VALIDATION_CONNECT_TIMEOUT', 10),
    'timeout' => (int) env('CPE_VALIDATION_TIMEOUT', 20),
];
