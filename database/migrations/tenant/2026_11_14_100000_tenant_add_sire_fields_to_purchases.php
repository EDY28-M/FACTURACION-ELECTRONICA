<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Data required by the SIRE RCE replacement file that purchases did not store:
 *  - sire_destination: destination of the acquisition (DG, DGNG or DNG).
 *  - affected_*: modified document of a credit/debit note received.
 */
class TenantAddSireFieldsToPurchases extends Migration
{
    public function up()
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->string('sire_destination', 4)->default('DG');
            $table->string('affected_document_type_id', 2)->nullable();
            $table->string('affected_series', 20)->nullable();
            $table->string('affected_number', 20)->nullable();
            $table->date('affected_date_of_issue')->nullable();
        });
    }

    public function down()
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->dropColumn([
                'sire_destination',
                'affected_document_type_id',
                'affected_series',
                'affected_number',
                'affected_date_of_issue',
            ]);
        });
    }
}
