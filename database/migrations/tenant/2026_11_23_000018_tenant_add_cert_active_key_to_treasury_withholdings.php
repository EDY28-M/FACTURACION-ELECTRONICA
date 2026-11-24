<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Additive, idempotent tenant migration (Treasury open-items closure).
 *
 * Adds `treasury_withholdings.cert_active_key` ("retention:{certificate}") with a UNIQUE index. `active_link_key` is
 * unique per (certificate, payment), so it cannot stop two concurrent requests from linking the SAME retention
 * certificate to two different sets of payments. The certificate-level key is set only on the FIRST row of an active
 * set and is NULL on every other row and after voiding (multiple NULLs are allowed by the unique index).
 */
class TenantAddCertActiveKeyToTreasuryWithholdings extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('treasury_withholdings') || Schema::hasColumn('treasury_withholdings', 'cert_active_key')) {
            return;
        }

        Schema::table('treasury_withholdings', function (Blueprint $table) {
            $table->string('cert_active_key', 40)->nullable();
        });

        // Backfill: the lowest id of each active retention certificate keeps the key.
        $assigned = [];
        $rows = DB::table('treasury_withholdings')
            ->where('state', 'active')
            ->where('kind', 'retention_emitted')
            ->whereNotNull('retention_id')
            ->orderBy('id')
            ->get(['id', 'retention_id']);

        foreach ($rows as $row) {
            $key = 'retention:' . $row->retention_id;
            if (isset($assigned[$key])) {
                continue;
            }
            $assigned[$key] = true;
            DB::table('treasury_withholdings')->where('id', $row->id)->update(['cert_active_key' => $key]);
        }

        Schema::table('treasury_withholdings', function (Blueprint $table) {
            $table->unique('cert_active_key', 'tw_cert_active_key_unique');
        });
    }

    public function down()
    {
        if (Schema::hasTable('treasury_withholdings') && Schema::hasColumn('treasury_withholdings', 'cert_active_key')) {
            Schema::table('treasury_withholdings', function (Blueprint $table) {
                $table->dropUnique('tw_cert_active_key_unique');
                $table->dropColumn('cert_active_key');
            });
        }
    }
}
