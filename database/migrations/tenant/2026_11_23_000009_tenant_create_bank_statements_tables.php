<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive tenant migration (Treasury Phase 5, bank reconciliation).
 *
 * - bank_statements: one imported bank statement per account and period (draft -> closed).
 * - bank_statement_lines: the statement lines, amount signed (+ inflow / - outflow).
 * - bank_statement_mappings: saved column mappings per bank / account for CSV and Excel imports.
 *
 * Idempotent (hasTable guards). down() drops only the objects created here.
 */
class TenantCreateBankStatementsTables extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('bank_statements')) {
            Schema::create('bank_statements', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('bank_account_id');
                $table->date('period_from');
                $table->date('period_to');
                $table->decimal('opening_balance', 14, 2)->default(0);
                $table->decimal('closing_balance', 14, 2)->default(0);
                $table->string('file_name', 255)->nullable();
                $table->string('file_path', 255)->nullable();
                $table->unsignedInteger('imported_by');
                $table->string('status', 10)->default('draft'); // draft | closed
                $table->dateTime('closed_at')->nullable();
                $table->unsignedInteger('closed_by')->nullable();
                $table->json('snapshot')->nullable(); // closing snapshot: bank vs system balance, in-transit items
                $table->timestamps();

                $table->index(['bank_account_id', 'period_from', 'period_to'], 'bs_account_period_idx');
                $table->index('status', 'bs_status_idx');
            });
        }

        if (!Schema::hasTable('bank_statement_lines')) {
            Schema::create('bank_statement_lines', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('bank_statement_id');
                $table->unsignedInteger('line_no');
                $table->date('date');
                $table->string('description', 500);
                $table->string('reference', 100)->nullable();
                $table->decimal('amount', 14, 2); // signed: positive = inflow, negative = outflow
                $table->string('status', 10)->default('unmatched'); // unmatched | matched | ignored
                $table->string('ignore_reason', 255)->nullable();
                $table->unsignedInteger('ignored_by')->nullable();
                $table->timestamps();

                $table->unique(['bank_statement_id', 'line_no'], 'bsl_statement_line_unique');
                $table->index(['bank_statement_id', 'status'], 'bsl_statement_status_idx');
            });
        }

        if (!Schema::hasTable('bank_statement_mappings')) {
            Schema::create('bank_statement_mappings', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('bank_id')->nullable();
                $table->unsignedInteger('bank_account_id')->nullable();
                $table->string('name', 100);
                $table->json('config');
                $table->unsignedInteger('created_by')->nullable();
                $table->timestamps();

                $table->index('bank_id', 'bsm_bank_idx');
                $table->index('bank_account_id', 'bsm_account_idx');
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('bank_statement_mappings');
        Schema::dropIfExists('bank_statement_lines');
        Schema::dropIfExists('bank_statements');
    }
}
