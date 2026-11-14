<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class TenantAddGreApiFields extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('gre_client_id', 100)->nullable();
            // Stored encrypted (Laravel encrypt/decrypt), never exposed to the frontend.
            $table->text('gre_client_secret')->nullable();
        });

        Schema::table('dispatches', function (Blueprint $table) {
            $table->string('ticket', 100)->nullable();
            $table->string('sunat_qr_url', 500)->nullable();
            $table->longText('qr')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['gre_client_id', 'gre_client_secret']);
        });

        Schema::table('dispatches', function (Blueprint $table) {
            $table->dropColumn(['ticket', 'sunat_qr_url', 'qr']);
        });
    }
}
