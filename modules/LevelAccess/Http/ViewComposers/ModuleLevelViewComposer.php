<?php

namespace Modules\LevelAccess\Http\ViewComposers;

use Modules\LevelAccess\Models\ModuleLevel;

class ModuleLevelViewComposer
{
    public function compose($view)
    {
        $user = auth()->user();

        // Only the owner sees every level; any other user sees exactly what was granted (no rows = nothing).
        if ($user && $user->isOwner()) {
            $view->vc_module_levels = ModuleLevel::all()->pluck('value')->toArray();
        } else {
            $view->vc_module_levels = $user ? $user->levelValues() : [];
        }
    }
}
