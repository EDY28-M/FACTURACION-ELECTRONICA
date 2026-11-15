<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SUNAT Catalog 09: credit note type 13 "Ajustes - montos y/o fechas de pago".
 */
class AddCreditNoteType13 extends Migration
{
    public function up()
    {
        $exists = DB::table('cat_note_credit_types')->where('id', '13')->exists();

        if (!$exists) {
            DB::table('cat_note_credit_types')->insert([
                'id' => '13',
                'active' => true,
                'description' => 'Ajustes - montos y/o fechas de pago',
            ]);
        }
    }

    public function down()
    {
        $inUse = DB::table('notes')->where('note_credit_type_id', '13')->exists();

        if ($inUse) {
            // Keep the catalog row referenced by existing notes; only deactivate it.
            DB::table('cat_note_credit_types')->where('id', '13')->update(['active' => false]);

            return;
        }

        DB::table('cat_note_credit_types')->where('id', '13')->delete();
    }
}
