<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TenantAddTreasuryColumnsToExistingTables extends Migration
{
    public function up()
    {
        if (Schema::hasTable('bank_accounts')) {
            Schema::table('bank_accounts', function (Blueprint $table) {
                if (!Schema::hasColumn('bank_accounts', 'account_type')) {
                    $table->string('account_type', 20)->default('checking');
                }
                if (!Schema::hasColumn('bank_accounts', 'pcge_account')) {
                    $table->string('pcge_account', 20)->nullable();
                }
                if (!Schema::hasColumn('bank_accounts', 'itf_exempt')) {
                    $table->boolean('itf_exempt')->default(false);
                }
                if (!Schema::hasColumn('bank_accounts', 'establishment_id')) {
                    $table->unsignedInteger('establishment_id')->nullable();
                    $table->index('establishment_id', 'ba_establishment_idx');
                }
            });
        }

        if (Schema::hasTable('payment_method_types')) {
            Schema::table('payment_method_types', function (Blueprint $table) {
                if (!Schema::hasColumn('payment_method_types', 'sunat_code')) {
                    $table->char('sunat_code', 3)->nullable();
                }
                if (!Schema::hasColumn('payment_method_types', 'is_cash')) {
                    $table->boolean('is_cash')->default(false);
                }
            });

            // Initial SUNAT Table 1 / SIRE Table 10 mapping (idempotent; only fills unset sunat_code).
            $seedMap = [
                '01' => ['sunat_code' => '008', 'is_cash' => true],
                '02' => ['sunat_code' => '006', 'is_cash' => false],
                '03' => ['sunat_code' => '005', 'is_cash' => false],
                '04' => ['sunat_code' => '003', 'is_cash' => false],
                '06' => ['sunat_code' => '006', 'is_cash' => false],
            ];

            foreach ($seedMap as $id => $values) {
                DB::table('payment_method_types')
                    ->where('id', $id)
                    ->whereNull('sunat_code')
                    ->update($values);
            }
        }

        if (Schema::hasTable('companies')) {
            Schema::table('companies', function (Blueprint $table) {
                if (!Schema::hasColumn('companies', 'is_retention_agent')) {
                    $table->boolean('is_retention_agent')->default(false);
                }
                if (!Schema::hasColumn('companies', 'retention_agent_since')) {
                    $table->date('retention_agent_since')->nullable();
                }
                if (!Schema::hasColumn('companies', 'is_perception_agent')) {
                    $table->boolean('is_perception_agent')->default(false);
                }
                if (!Schema::hasColumn('companies', 'is_good_taxpayer')) {
                    $table->boolean('is_good_taxpayer')->default(false);
                }
            });
        }
    }

    public function down()
    {
        if (Schema::hasTable('bank_accounts')) {
            foreach (['account_type', 'pcge_account', 'itf_exempt', 'establishment_id'] as $col) {
                if (Schema::hasColumn('bank_accounts', $col)) {
                    Schema::table('bank_accounts', function (Blueprint $table) use ($col) {
                        if ($col === 'establishment_id') {
                            try {
                                $table->dropIndex('ba_establishment_idx');
                            } catch (\Throwable $e) {
                                // Index may not exist on older installations.
                            }
                        }
                        $table->dropColumn($col);
                    });
                }
            }
        }

        if (Schema::hasTable('payment_method_types')) {
            foreach (['sunat_code', 'is_cash'] as $col) {
                if (Schema::hasColumn('payment_method_types', $col)) {
                    Schema::table('payment_method_types', function (Blueprint $table) use ($col) {
                        $table->dropColumn($col);
                    });
                }
            }
        }

        if (Schema::hasTable('companies')) {
            foreach (['is_retention_agent', 'retention_agent_since', 'is_perception_agent', 'is_good_taxpayer'] as $col) {
                if (Schema::hasColumn('companies', $col)) {
                    Schema::table('companies', function (Blueprint $table) use ($col) {
                        $table->dropColumn($col);
                    });
                }
            }
        }
    }
}
