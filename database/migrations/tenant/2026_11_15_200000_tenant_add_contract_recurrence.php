<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recurrent billing for contracts:
 *  - contracts: recurrence settings (manual or automatic mode) and the next billing date.
 *  - contract_billings: one row per billed period (UNIQUE contract + period) so a period is never billed twice.
 */
class TenantAddContractRecurrence extends Migration
{
    public function up()
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->boolean('recurrence_enabled')->default(false);
            $table->string('recurrence_mode', 10)->default('manual'); // manual | automatic
            $table->string('recurrence_period', 5)->nullable(); // month | year
            $table->unsignedSmallInteger('recurrence_interval')->default(1);
            $table->date('recurrence_start_date')->nullable();
            $table->date('recurrence_end_date')->nullable();
            $table->unsignedTinyInteger('recurrence_day')->nullable();
            $table->date('next_billing_date')->nullable();
            $table->char('billing_document_type_id', 2)->nullable(); // 01 | 03
            $table->string('billing_series', 4)->nullable();
            $table->index(['recurrence_enabled', 'next_billing_date'], 'contracts_recurrence_due_index');
        });

        Schema::create('contract_billings', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('contract_id');
            $table->date('period_date');
            $table->unsignedInteger('document_id')->nullable();
            $table->string('status', 10); // issued | failed
            $table->text('error')->nullable();
            $table->string('mode', 10); // manual | automatic
            $table->timestamps();

            $table->unique(['contract_id', 'period_date'], 'contract_billings_period_unique');
            $table->foreign('contract_id')->references('id')->on('contracts')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('contract_billings');

        Schema::table('contracts', function (Blueprint $table) {
            $table->dropIndex('contracts_recurrence_due_index');
            $table->dropColumn([
                'recurrence_enabled', 'recurrence_mode', 'recurrence_period', 'recurrence_interval',
                'recurrence_start_date', 'recurrence_end_date', 'recurrence_day', 'next_billing_date',
                'billing_document_type_id', 'billing_series',
            ]);
        });
    }
}
