<?php

namespace App\Http\ViewComposers\Tenant;

use App\Models\System\Configuration;

class ModuleViewComposer
{
    public function compose($view)
    {
        $user = auth()->user();
        $systemConfig = Configuration::select('use_login_global')->first();

        // Effective values: the tenant plan for the owner, own grants capped by the plan for everyone else.
        $view->vc_modules = $user ? $user->moduleValues() : [];
        $view->useLoginGlobal = $systemConfig->use_login_global;
    }
}
