<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive tenant migration (Treasury Phase 5 hardening).
 *
 * Records when a statement was imported with "accept unbalanced" (opening balance + lines != closing balance):
 * `imported_unbalanced` flags it and `unbalanced_gap` stores closing - (opening + lines). Idempotent (hasColumn guards).
 */
class TenantAddUnbalancedImportFlagToBankStatements extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('bank_statements')) {
            return;
        }

        if (!Schema::hasColumn('bank_statements', 'imported_unbalanced')) {
            Schema::table('bank_statements', function (Blueprint $table) {
                $table->boolean('imported_unbalanced')->default(false);
            });
        }

        if (!Schema::hasColumn('bank_statements', 'unbalanced_gap')) {
            Schema::table('bank_statements', function (Blueprint $table) {
                $table->decimal('unbalanced_gap', 14, 2)->nullable();
            });
        }
    }

    public function down()
    {
        if (!Schema::hasTable('bank_statements')) {
            return;
        }

        foreach (['unbalanced_gap', 'imported_unbalanced'] as $column) {
            if (Schema::hasColumn('bank_statements', $column)) {
                Schema::table('bank_statements', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }
}
