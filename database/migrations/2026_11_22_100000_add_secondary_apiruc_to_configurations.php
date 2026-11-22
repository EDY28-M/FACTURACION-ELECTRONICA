<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddSecondaryApirucToConfigurations extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('configurations', function (Blueprint $table) {
            $table->string('secondary_url_apiruc')->nullable();
            $table->text('secondary_token_apiruc')->nullable();
            $table->string('apiruc_primary', 20)->default('primary');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('configurations', function (Blueprint $table) {
            $table->dropColumn(['secondary_url_apiruc', 'secondary_token_apiruc', 'apiruc_primary']);
        });
    }
}
