<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive tenant migration (Treasury Phase 6, cash count / arqueo de caja).
 *
 * Idempotent (hasTable guard). down() drops only treasury_cash_counts.
 */
class TenantCreateTreasuryCashCountsTable extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('treasury_cash_counts')) {
            Schema::create('treasury_cash_counts', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('cash_id');
                $table->unsignedInteger('establishment_id')->nullable();
                $table->dateTime('counted_at');
                $table->unsignedInteger('user_id');
                $table->decimal('system_balance', 14, 2);
                $table->decimal('counted_balance', 14, 2);
                $table->decimal('difference', 14, 2);
                $table->json('denominations');
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index('cash_id', 'tcc_cash_idx');
                $table->index('establishment_id', 'tcc_est_idx');
                $table->index('counted_at', 'tcc_counted_at_idx');
                $table->index('user_id', 'tcc_user_idx');

                $table->foreign('cash_id')->references('id')->on('cash')->onDelete('cascade');
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('treasury_cash_counts');
    }
}
