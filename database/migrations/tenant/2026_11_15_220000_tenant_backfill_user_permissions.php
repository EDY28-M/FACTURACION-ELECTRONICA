<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Access control no longer treats "no rows" as "full access". To keep what existing users effectively
 * had until now, materialise the implicit grants:
 *  - a non-owner user with ZERO module rows receives every module and every level;
 *  - a non-owner user with the sales module ("documents") but ZERO level rows receives every level.
 * The owner (first user) is never touched: it always has full access.
 */
class TenantBackfillUserPermissions extends Migration
{
    public function up()
    {
        $db = DB::connection('tenant');

        $ownerId = $db->table('users')->min('id');
        if ($ownerId === null) {
            return;
        }

        $moduleIds = $db->table('modules')->pluck('id')->all();
        $levelIds = $db->table('module_levels')->pluck('id')->all();
        $salesModuleId = $db->table('modules')->where('value', 'documents')->value('id');

        $withoutModules = 0;
        $withoutLevels = 0;

        foreach ($db->table('users')->where('id', '!=', $ownerId)->pluck('id') as $userId) {
            $userModules = $db->table('module_user')->where('user_id', $userId)->pluck('module_id')->all();

            if (count($userModules) === 0) {
                $db->table('module_user')->insert(array_map(function ($id) use ($userId) {
                    return ['user_id' => $userId, 'module_id' => $id];
                }, $moduleIds));
                $userModules = $moduleIds;
                $withoutModules++;
                $grantLevels = true;
            } else {
                $grantLevels = $salesModuleId !== null && in_array($salesModuleId, $userModules)
                    && $db->table('module_level_user')->where('user_id', $userId)->count() === 0;
                if ($grantLevels) {
                    $withoutLevels++;
                }
            }

            if ($grantLevels) {
                $existing = $db->table('module_level_user')->where('user_id', $userId)->pluck('module_level_id')->all();
                $missing = array_values(array_diff($levelIds, $existing));

                if (count($missing) > 0) {
                    $db->table('module_level_user')->insert(array_map(function ($id) use ($userId) {
                        return ['user_id' => $userId, 'module_level_id' => $id];
                    }, $missing));
                }
            }
        }

        Log::info('permissions backfill', [
            'users_granted_all_modules_and_levels' => $withoutModules,
            'users_granted_all_levels' => $withoutLevels,
        ]);
    }

    /**
     * Intentionally a no-op: the granted rows cannot be told apart from rows set by an administrator.
     */
    public function down()
    {
    }
}
