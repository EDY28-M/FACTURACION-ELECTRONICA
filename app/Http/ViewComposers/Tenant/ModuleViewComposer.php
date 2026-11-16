<?php

namespace App\Http\ViewComposers\Tenant;

use App\Models\System\Configuration;
use App\Models\Tenant\Module;

class ModuleViewComposer
{
    public function compose($view)
    {
        $user = auth()->user();
        $systemConfig = Configuration::select('use_login_global')->first();

        // Only the owner sees everything; any other user sees exactly what was granted (no rows = nothing).
        if ($user && $user->isOwner()) {
            $view->vc_modules = Module::all()->pluck('value')->toArray();
        } else {
            $view->vc_modules = $user ? $user->moduleValues() : [];
        }
        $view->useLoginGlobal = $systemConfig->use_login_global;
    }
}
