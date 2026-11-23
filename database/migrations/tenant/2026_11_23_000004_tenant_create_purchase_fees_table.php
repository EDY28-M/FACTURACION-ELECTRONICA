<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive tenant migration creating `purchase_fees` for manual purchase installment schedules (Phase 3).
 */
class TenantCreatePurchaseFeesTable extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('purchase_fees')) {
            Schema::create('purchase_fees', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('purchase_id');
                $table->date('date');
                $table->string('currency_type_id', 3);
                $table->decimal('amount', 12, 2);
                $table->timestamps();

                $table->index('purchase_id', 'pf_purchase_idx');
                $table->index('date', 'pf_date_idx');
                $table->foreign('purchase_id')->references('id')->on('purchases')->onDelete('cascade');
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('purchase_fees');
    }
}
