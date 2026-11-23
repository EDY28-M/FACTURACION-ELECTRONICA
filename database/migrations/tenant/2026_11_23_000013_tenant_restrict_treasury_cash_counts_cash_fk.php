<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Additive tenant migration (Treasury Phase 6, cash count audit integrity).
 *
 * The first version of 2026_11_23_000012 created treasury_cash_counts.cash_id with ON DELETE CASCADE, which would
 * erase arqueo audit records when a cash shift is deleted. This migration recreates that foreign key as RESTRICT
 * on databases that already ran the first version. Idempotent: it does nothing when the FK is already RESTRICT
 * (fresh installs) or when the table does not exist.
 */
class TenantRestrictTreasuryCashCountsCashFk extends Migration
{
    private const FK_NAME = 'treasury_cash_counts_cash_id_foreign';

    public function up()
    {
        if (!Schema::hasTable('treasury_cash_counts') || DB::getDriverName() !== 'mysql') {
            return;
        }

        $rule = $this->currentDeleteRule();
        if ($rule === null || strtoupper($rule) === 'RESTRICT') {
            return;
        }

        Schema::table('treasury_cash_counts', function (Blueprint $table) {
            $table->dropForeign(self::FK_NAME);
        });
        Schema::table('treasury_cash_counts', function (Blueprint $table) {
            $table->foreign('cash_id', self::FK_NAME)->references('id')->on('cash')->onDelete('restrict');
        });
    }

    public function down()
    {
        // Intentionally empty: reverting to CASCADE would reintroduce the audit-record loss.
    }

    private function currentDeleteRule(): ?string
    {
        $rows = DB::select(
            'select DELETE_RULE as delete_rule from information_schema.REFERENTIAL_CONSTRAINTS '
            . 'where CONSTRAINT_SCHEMA = database() and TABLE_NAME = ? and CONSTRAINT_NAME = ?',
            ['treasury_cash_counts', self::FK_NAME]
        );

        return !empty($rows) ? (string) $rows[0]->delete_rule : null;
    }
}
