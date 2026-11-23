<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TenantAddDataModuleForTreasury extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('modules')) {
            return;
        }

        if (DB::table('modules')->where('value', 'treasury')->exists()) {
            return;
        }

        if (DB::table('modules')->where('id', 19)->exists()) {
            throw new \RuntimeException('Module id 19 is already occupied by another module in tenant database.');
        }

        $payload = [
            'id' => 19,
            'value' => 'treasury',
            'description' => 'Tesorería',
        ];

        if (Schema::hasColumn('modules', 'order_menu')) {
            $payload['order_menu'] = 16;
        }

        DB::table('modules')->insert($payload);
    }

    public function down()
    {
        if (!Schema::hasTable('modules')) {
            return;
        }

        DB::table('modules')
            ->where('id', 19)
            ->where('value', 'treasury')
            ->delete();
    }
}
