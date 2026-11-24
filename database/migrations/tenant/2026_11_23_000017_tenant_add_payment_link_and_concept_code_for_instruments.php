<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Additive, idempotent tenant migration (Treasury Phase 7 review fixes).
 *
 * - `treasury_instruments.payment_type` / `payment_id`: the legacy payment (DocumentPayment / PurchasePayment) created
 *   when an instrument LINKED to a document or purchase is collected. Money for linked instruments lives in that
 *   payment (D4: Treasury never duplicates document payments), not in a treasury movement.
 * - `treasury_concepts.code`: stable key so code never resolves a concept by its (editable) description.
 *   The two instrument concepts seeded by migration 000016 are tagged `instrument_income` / `instrument_expense`.
 */
class TenantAddPaymentLinkAndConceptCodeForInstruments extends Migration
{
    public function up()
    {
        if (Schema::hasTable('treasury_instruments') && !Schema::hasColumn('treasury_instruments', 'payment_id')) {
            Schema::table('treasury_instruments', function (Blueprint $table) {
                $table->string('payment_type', 150)->nullable();
                $table->unsignedInteger('payment_id')->nullable();
                $table->index(['payment_type', 'payment_id'], 'ti_payment_idx');
            });
        }

        if (Schema::hasTable('treasury_concepts')) {
            if (!Schema::hasColumn('treasury_concepts', 'code')) {
                Schema::table('treasury_concepts', function (Blueprint $table) {
                    $table->string('code', 40)->nullable();
                    $table->index('code', 'tc_code_idx');
                });
            }

            foreach ([
                ['income', 'Cobro de cheques y letras', 'instrument_income'],
                ['expense', 'Pago de cheques y letras', 'instrument_expense'],
            ] as [$type, $description, $code]) {
                $alreadyTagged = DB::table('treasury_concepts')->where('code', $code)->exists();
                if ($alreadyTagged) {
                    continue;
                }

                DB::table('treasury_concepts')
                    ->where('type', $type)
                    ->where('description', $description)
                    ->whereNull('code')
                    ->orderBy('id')
                    ->limit(1)
                    ->update(['code' => $code]);
            }
        }
    }

    public function down()
    {
        if (Schema::hasTable('treasury_instruments') && Schema::hasColumn('treasury_instruments', 'payment_id')) {
            Schema::table('treasury_instruments', function (Blueprint $table) {
                $table->dropIndex('ti_payment_idx');
                $table->dropColumn(['payment_type', 'payment_id']);
            });
        }

        if (Schema::hasTable('treasury_concepts') && Schema::hasColumn('treasury_concepts', 'code')) {
            Schema::table('treasury_concepts', function (Blueprint $table) {
                $table->dropIndex('tc_code_idx');
                $table->dropColumn('code');
            });
        }
    }
}
