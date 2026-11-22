<?php

namespace App\Support\Access;

use App\Models\Tenant\Module;

/**
 * Builds the permission tree (modules with their levels) shown in the user form.
 * The source is the full catalogue (modules / module_levels), ordered as the sidebar.
 */
class PermissionTree
{
    /**
     * Flat node list for the tree component: parents first, children as "{moduleId}-{levelId}".
     * A module with levels is never checked by itself: its state comes from its children.
     *
     * @param int[] $checkedModuleIds modules granted to the user (relevant for modules without levels)
     * @param int[] $checkedLevelIds  levels granted to the user
     *
     * The tree only offers what the tenant plan includes (see TenantPlan).
     */
    public static function build(array $checkedModuleIds = [], array $checkedLevelIds = []): array
    {
        $nodes = [];
        $plan = TenantPlan::current();

        foreach (self::catalogue() as $module) {
            if (!in_array((int) $module->id, $plan->moduleIds(), true)) {
                continue;
            }

            $visibleLevels = $module->levels
                ->reject(function ($level) use ($plan) {
                    return $level->value === 'catalogs'
                        || !in_array((int) $level->id, $plan->levelIds(), true);
                })
                ->sortBy('id');
            $hasChild = $visibleLevels->isNotEmpty();

            $nodes[] = [
                'id'          => $module->id,
                'name'        => $module->description,
                'hasChild'    => $hasChild,
                'expanded'    => $hasChild,
                'isChecked'   => !$hasChild && in_array($module->id, $checkedModuleIds),
            ];

            foreach ($visibleLevels as $level) {
                $nodes[] = [
                    'id'        => $module->id.'-'.$level->id,
                    'pid'       => $module->id,
                    'name'      => $level->description,
                    'isChecked' => in_array($level->id, $checkedLevelIds),
                ];
            }
        }

        return $nodes;
    }

    private static function catalogue()
    {
        return Module::with('levels')->orderBy('order_menu')->orderBy('id')->get();
    }
}
