<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class TenantAddIgvReducedRegime extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('configurations', function (Blueprint $table) {
            // MYPE restaurants/hotels: reduced IGV (+IPM) schedule (Ley 31556 / 32219 / 32387).
            $table->boolean('igv_reduced_regime')->default(false);
        });

        Schema::table('items', function (Blueprint $table) {
            // Item that is NOT part of the restaurant/hotel activity: always taxed at the general rate.
            $table->boolean('igv_general_rate')->default(false);
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
            $table->dropColumn('igv_reduced_regime');
        });

        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn('igv_general_rate');
        });
    }
}
