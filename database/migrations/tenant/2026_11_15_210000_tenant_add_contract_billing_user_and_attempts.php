<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recurrent billing follow-up:
 *  - contracts.billing_user_id: user the invoices are issued as (set when recurrence is first enabled).
 *  - contracts.recurrence_backfill: also bill the periods before today when the start date is in the past.
 *  - contract_billings.attempts: failed attempts of the period (automatic mode stops retrying after a cap).
 */
class TenantAddContractBillingUserAndAttempts extends Migration
{
    public function up()
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->unsignedInteger('billing_user_id')->nullable();
            $table->boolean('recurrence_backfill')->default(false);
        });

        Schema::table('contract_billings', function (Blueprint $table) {
            $table->unsignedSmallInteger('attempts')->default(0);
        });
    }

    public function down()
    {
        Schema::table('contract_billings', function (Blueprint $table) {
            $table->dropColumn('attempts');
        });

        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn(['billing_user_id', 'recurrence_backfill']);
        });
    }
}
