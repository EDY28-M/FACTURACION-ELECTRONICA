<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * GRE Transportista (document type 31): carrier registration, sender, freight payer,
 * related sender guides, subcontracting and vehicle authorization data.
 */
class TenantAddGreTransportistaFields extends Migration
{
    public function up()
    {
        Schema::table('companies', function (Blueprint $table) {
            // MTC (Ministerio de Transportes y Comunicaciones) registration number of the carrier.
            $table->string('mtc_registration_number', 20)->nullable();
        });

        Schema::table('dispatches', function (Blueprint $table) {
            $table->longText('sender')->nullable();
            $table->string('freight_payer', 20)->nullable();
            $table->longText('freight_payer_party')->nullable();
            $table->longText('related_dispatches')->nullable();
            $table->longText('subcontractor')->nullable();
            $table->longText('vehicle_details')->nullable();
            $table->longText('indicators')->nullable();
        });

        DB::table('cat_document_types')->where('id', '31')
            ->update(['description' => 'GUÍA DE REMISIÓN ELECTRÓNICA TRANSPORTISTA']);
    }

    public function down()
    {
        DB::table('cat_document_types')->where('id', '31')
            ->update(['description' => 'Guía de remisión transportista']);

        Schema::table('dispatches', function (Blueprint $table) {
            $table->dropColumn(['sender', 'freight_payer', 'freight_payer_party', 'related_dispatches',
                'subcontractor', 'vehicle_details', 'indicators']);
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('mtc_registration_number');
        });
    }
}
