<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class TenantAddSireApi extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('sire_client_id', 100)->nullable();
            // Stored encrypted (Laravel encrypt/decrypt), never exposed to the frontend.
            $table->text('sire_client_secret')->nullable();
        });

        Schema::create('sire_requests', function (Blueprint $table) {
            $table->increments('id');
            $table->string('book', 6)->default('140000');
            $table->string('period', 6);
            $table->string('ticket', 50)->nullable();
            // requested | processing | ready | failed | reconciled
            $table->string('status', 20)->default('requested');
            $table->string('status_detail', 500)->nullable();
            $table->string('file_name', 255)->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->json('summary')->nullable();
            $table->timestamps();

            $table->index(['book', 'period']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('sire_requests');

        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['sire_client_id', 'sire_client_secret']);
        });
    }
}
