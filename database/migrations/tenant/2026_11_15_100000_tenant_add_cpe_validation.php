<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SUNAT "Consulta Integrada de Validez de Comprobantes de Pago" API:
 *  - companies: credentials of the application registered in SOL for that API (secret stored encrypted).
 *  - purchases: last validation result of each supplier document.
 */
class TenantAddCpeValidation extends Migration
{
    public function up()
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('cpe_client_id', 100)->nullable();
            $table->text('cpe_client_secret')->nullable();
        });

        Schema::table('purchases', function (Blueprint $table) {
            // no_existe | aceptado | anulado | autorizado | no_autorizado | no_aplica | error
            $table->string('sunat_validation_status', 20)->nullable();
            $table->json('sunat_validation_detail')->nullable();
            $table->dateTime('sunat_validated_at')->nullable();
        });
    }

    public function down()
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->dropColumn(['sunat_validation_status', 'sunat_validation_detail', 'sunat_validated_at']);
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['cpe_client_id', 'cpe_client_secret']);
        });
    }
}
