<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Additive tenant migration (Treasury Phase 4 hardening).
 *
 * Adds `treasury_withholdings.active_link_key` ("retention:{id}" / "perception:{id}") with a UNIQUE index so the
 * same issued CRE/CPE cannot be linked to two payments concurrently. The key is set while the link is active and
 * NULL after it is voided (multiple NULLs are allowed by the unique index). Idempotent: guarded by hasColumn.
 */
class TenantAddActiveLinkKeyToTreasuryWithholdings extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('treasury_withholdings') || Schema::hasColumn('treasury_withholdings', 'active_link_key')) {
            return;
        }

        Schema::table('treasury_withholdings', function (Blueprint $table) {
            $table->string('active_link_key', 40)->nullable()->after('perception_id');
        });

        // Backfill existing active links; if legacy data already contains duplicates only the first keeps the key.
        $assigned = [];
        $rows = DB::table('treasury_withholdings')
            ->where('state', 'active')
            ->whereIn('kind', ['retention_emitted', 'perception_emitted'])
            ->orderBy('id')
            ->get(['id', 'kind', 'retention_id', 'perception_id']);

        foreach ($rows as $row) {
            $key = null;
            if ($row->kind === 'retention_emitted' && $row->retention_id) {
                $key = 'retention:' . $row->retention_id;
            } elseif ($row->kind === 'perception_emitted' && $row->perception_id) {
                $key = 'perception:' . $row->perception_id;
            }

            if ($key === null || isset($assigned[$key])) {
                continue;
            }

            $assigned[$key] = true;
            DB::table('treasury_withholdings')->where('id', $row->id)->update(['active_link_key' => $key]);
        }

        Schema::table('treasury_withholdings', function (Blueprint $table) {
            $table->unique('active_link_key', 'tw_active_link_key_unique');
        });
    }

    public function down()
    {
        if (Schema::hasTable('treasury_withholdings') && Schema::hasColumn('treasury_withholdings', 'active_link_key')) {
            Schema::table('treasury_withholdings', function (Blueprint $table) {
                $table->dropUnique('tw_active_link_key_unique');
                $table->dropColumn('active_link_key');
            });
        }
    }
}
