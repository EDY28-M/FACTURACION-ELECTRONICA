<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Additive, idempotent seed (Treasury Phase 4 hardening): business day (of the month after the Registro de
 * Compras entry) by which the buyer must deposit a detraction. Stored in `treasury_tax_rates.amount` so the
 * regulatory value is versioned by date instead of hardcoded.
 * Source: docs/plans/tesoreria-investigacion-sunat.md section 2 ("When").
 */
class TenantSeedDetractionDepositBusinessDayRate extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('treasury_tax_rates')) {
            return;
        }

        $exists = DB::table('treasury_tax_rates')
            ->where('tax', 'detraction_deposit_business_day')
            ->whereNull('code')
            ->where('valid_from', '2018-04-01')
            ->exists();

        if ($exists) {
            return;
        }

        $now = date('Y-m-d H:i:s');
        DB::table('treasury_tax_rates')->insert([
            'tax' => 'detraction_deposit_business_day',
            'code' => null,
            'rate' => null,
            'amount' => 5,
            'min_operation' => null,
            'valid_from' => '2018-04-01',
            'valid_to' => null,
            'legal_reference' => 'SUNAT orientacion 3142-03: deposito hasta el 5to dia habil del mes siguiente a la anotacion en el Registro de Compras [REVISAR: anuncio SUNAT 2026-09-11]',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down()
    {
        if (Schema::hasTable('treasury_tax_rates')) {
            DB::table('treasury_tax_rates')->where('tax', 'detraction_deposit_business_day')->delete();
        }
    }
}
