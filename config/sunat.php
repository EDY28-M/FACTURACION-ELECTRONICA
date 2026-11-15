<?php

return [
    /*
    | First issue date (Y-m-d) from which a valid Catalogo 25 product code is mandatory on
    | facturas, boletas and notes (and liquidacion de compra).
    | [unverified] Taken from vendor sources; the official SUNAT page
    | (cpe.sunat.gob.pe/informacion_general/codigoproducto, 13/07/2026) states it is not a minimum
    | requirement today and that invalid codes are only observed (OBS-3496).
    */
    'product_code_required_from' => env('SUNAT_PRODUCT_CODE_REQUIRED_FROM', '2027-01-01'),
];
