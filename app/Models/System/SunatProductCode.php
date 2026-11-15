<?php

namespace App\Models\System;

use Illuminate\Database\Eloquent\Model;

/**
 * SUNAT Catalogo 25 (UNSPSC v14_0801). Stored once in the system (landlord) database.
 * Never add a tenant connection here: tenant code reads it through the 'system' connection.
 */
class SunatProductCode extends Model
{
    protected $connection = 'system';
    protected $table = 'sunat_product_codes';
    protected $primaryKey = 'code';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = ['code', 'description', 'level'];
}
