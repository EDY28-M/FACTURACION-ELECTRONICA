<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive tenant migration (Treasury Phase 5, bank reconciliation).
 *
 * Pivot between a statement line and the system items it explains (TreasuryMovement | GlobalPayment). It supports
 * one-to-one and one-to-many matches without adding columns to `global_payments` (decision D5).
 *
 * `active_match_key` ("{matchable_type}:{matchable_id}") is set while the match is active and NULL once released;
 * its UNIQUE index guarantees the same system item can never back two active matches (multiple NULLs are allowed).
 */
class TenantCreateBankStatementLineMatchesTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('bank_statement_line_matches')) {
            return;
        }

        Schema::create('bank_statement_line_matches', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('bank_statement_line_id');
            $table->string('matchable_type', 120);
            $table->unsignedBigInteger('matchable_id');
            $table->decimal('amount', 14, 2); // signed amount of the matched system item (account currency)
            $table->date('item_date'); // date of the system item at match time (used by the closing computation)
            $table->string('active_match_key', 160)->nullable();
            $table->unsignedInteger('matched_by')->nullable();
            $table->dateTime('released_at')->nullable();
            $table->unsignedInteger('released_by')->nullable();
            $table->timestamps();

            $table->unique('active_match_key', 'bslm_active_match_key_unique');
            $table->index('bank_statement_line_id', 'bslm_line_idx');
            $table->index(['matchable_type', 'matchable_id'], 'bslm_matchable_idx');
        });
    }

    public function down()
    {
        Schema::dropIfExists('bank_statement_line_matches');
    }
}
