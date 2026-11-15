<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Operation type 0202 (export of services: lodging for non-domiciled guests) and the
 * Catalogo 55 lodging properties 4000-4005 used by its lines. Passport ('7') is already active.
 */
class TenantEnableLodgingExport0202 extends Migration
{
    private const PROPERTIES = [
        '4000' => 'Beneficio Hospedajes-Paquete turístico: Código de país de emisión del pasaporte',
        '4001' => 'Beneficio Hospedajes: Código de país de residencia del sujeto no domiciliado',
        '4002' => 'Beneficio Hospedajes: Fecha de ingreso al país',
        '4003' => 'Beneficio Hospedajes: Fecha de Ingreso al Establecimiento',
        '4004' => 'Beneficio Hospedajes: Fecha de Salida del Establecimiento',
        '4005' => 'Beneficio Hospedajes: Número de Días de Permanencia',
    ];

    public function up()
    {
        DB::table('cat_operation_types')->where('id', '0202')->update(['active' => true]);

        foreach (self::PROPERTIES as $id => $description) {
            DB::table('cat_attribute_types')->updateOrInsert(
                ['id' => $id],
                ['active' => true, 'description' => $description]
            );
        }
    }

    public function down()
    {
        DB::table('cat_operation_types')->where('id', '0202')->update(['active' => false]);
    }
}
