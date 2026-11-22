<?php

namespace Modules\LevelAccess\Http\ViewComposers;

class ModuleLevelViewComposer
{
    public function compose($view)
    {
        $user = auth()->user();

        // Effective values: the tenant plan for the owner, own grants capped by the plan for everyone else.
        $view->vc_module_levels = $user ? $user->levelValues() : [];
    }
}
