<?php

namespace App\Support\Access;

use Illuminate\Support\Facades\DB;

/**
 * The tenant "plan": the modules and levels the system admin granted to the company.
 *
 * The admin panel writes them as the owner's (lowest users.id) module_user / module_level_user rows.
 * Every user, owner included, is capped by the plan at read time. Other users' rows are never
 * deleted, so re-enabling a module in the admin restores their previous access.
 *
 * Legacy fallbacks (so old tenants are not locked out):
 *  - owner without module rows  => plan = every module
 *  - owner without level rows   => plan levels = every level of the plan modules
 *
 * Memoized per request and tenant database.
 */
class TenantPlan
{
    /** @var array<string, self> */
    private static $instances = [];

    /** @var int|null */
    private $ownerId;

    /** @var int[] */
    private $moduleIds = [];

    /** @var string[] */
    private $moduleValues = [];

    /** @var int[] */
    private $levelIds = [];

    /** @var string[] */
    private $levelValues = [];

    /** @var array<int, int> level id => module id */
    private $levelModule = [];

    public static function current(): self
    {
        $connection = DB::connection('tenant');
        $key = $connection->getDatabaseName();

        if (!isset(self::$instances[$key])) {
            self::$instances[$key] = new self($connection);
        }

        return self::$instances[$key];
    }

    /**
     * Forget the memoized plans (after the admin changes them, or between tests).
     */
    public static function flush(): void
    {
        self::$instances = [];
    }

    private function __construct($db)
    {
        $ownerId = $db->table('users')->min('id');
        $this->ownerId = $ownerId === null ? null : (int) $ownerId;

        $allModules = $db->table('modules')->pluck('value', 'id')->all();
        $allLevels = $db->table('module_levels')->get(['id', 'value', 'module_id']);

        $ownerModuleIds = $this->ownerId === null
            ? []
            : $db->table('module_user')->where('user_id', $this->ownerId)->pluck('module_id')->map('intval')->all();

        $moduleIds = $ownerModuleIds ?: array_map('intval', array_keys($allModules));
        // Only ids that still exist in the catalogue.
        $this->moduleIds = array_values(array_filter($moduleIds, function ($id) use ($allModules) {
            return isset($allModules[$id]);
        }));
        $this->moduleValues = array_values(array_map(function ($id) use ($allModules) {
            return $allModules[$id];
        }, $this->moduleIds));

        $ownerLevelIds = $this->ownerId === null
            ? []
            : $db->table('module_level_user')->where('user_id', $this->ownerId)->pluck('module_level_id')->map('intval')->all();

        foreach ($allLevels as $level) {
            $levelId = (int) $level->id;
            $moduleId = (int) $level->module_id;
            $this->levelModule[$levelId] = $moduleId;

            if (!in_array($moduleId, $this->moduleIds, true)) {
                continue;
            }
            if ($ownerLevelIds && !in_array($levelId, $ownerLevelIds, true)) {
                continue;
            }

            $this->levelIds[] = $levelId;
            $this->levelValues[] = $level->value;
        }
    }

    public function ownerId(): ?int
    {
        return $this->ownerId;
    }

    /** @return int[] */
    public function moduleIds(): array
    {
        return $this->moduleIds;
    }

    /** @return string[] */
    public function moduleValues(): array
    {
        return $this->moduleValues;
    }

    /** @return int[] */
    public function levelIds(): array
    {
        return $this->levelIds;
    }

    /** @return string[] */
    public function levelValues(): array
    {
        return $this->levelValues;
    }

    /**
     * Module id a level belongs to, or null when unknown.
     */
    public function moduleOfLevel(int $levelId): ?int
    {
        return $this->levelModule[$levelId] ?? null;
    }
}
