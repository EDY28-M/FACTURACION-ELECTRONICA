<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive, idempotent tenant migration: SUNAT PLE Tabla 3 (entidad financiera) code of a bank account.
 *
 * The code is NEVER guessed: it stays NULL until the accountant sets it, and the PLE 1.2 export refuses to run
 * for accounts with movements that lack it.
 */
class TenantAddSunatFinancialEntityCodeToBankAccounts extends Migration
{
    public function up()
    {
        if (Schema::hasTable('bank_accounts') && !Schema::hasColumn('bank_accounts', 'sunat_financial_entity_code')) {
            Schema::table('bank_accounts', function (Blueprint $table) {
                $table->char('sunat_financial_entity_code', 2)->nullable();
            });
        }
    }

    public function down()
    {
        if (Schema::hasTable('bank_accounts') && Schema::hasColumn('bank_accounts', 'sunat_financial_entity_code')) {
            Schema::table('bank_accounts', function (Blueprint $table) {
                $table->dropColumn('sunat_financial_entity_code');
            });
        }
    }
}
