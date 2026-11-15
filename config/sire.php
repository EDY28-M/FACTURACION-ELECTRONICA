<?php

/*
|--------------------------------------------------------------------------
| SUNAT SIRE (Sistema Integrado de Registros Electronicos) REST API
|--------------------------------------------------------------------------
|
| Hosts from the "Manual de servicios web API - SIRE Ventas" v32 (section 5.1 and service URLs).
| SUNAT does NOT provide a SIRE sandbox: the API only works with real production data and credentials.
| Nothing here is a secret; client id/secret are stored per company.
|
*/

return [
    'token_url' => env('SIRE_TOKEN_URL', 'https://api-seguridad.sunat.gob.pe/v1'),
    'api_url' => env('SIRE_API_URL', 'https://api-sire.sunat.gob.pe/v1/contribuyente/migeigv/libros'),
    'scope' => env('SIRE_SCOPE', 'https://api-sire.sunat.gob.pe'),

    'connect_timeout' => (int) env('SIRE_CONNECT_TIMEOUT', 10),
    'timeout' => (int) env('SIRE_TIMEOUT', 60),
];
