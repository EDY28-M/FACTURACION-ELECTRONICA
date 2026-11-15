<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSunatProductCodesTable extends Migration
{
    public function up()
    {
        Schema::connection('system')->create('sunat_product_codes', function (Blueprint $table) {
            $table->char('code', 8)->primary();
            $table->string('description');
            $table->enum('level', ['class', 'product']);
            $table->index('level');
            $table->index('description');
        });
    }

    public function down()
    {
        Schema::connection('system')->dropIfExists('sunat_product_codes');
    }
}
