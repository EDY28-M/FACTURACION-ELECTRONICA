<?php

namespace App\Http\Middleware;

use App\Support\Access\TenantPlan;
use Closure;
use Illuminate\Http\Request;

/**
 * API guard: the request is allowed only when the tenant plan (the modules the system admin
 * enabled for the company) includes at least one of the given modules.
 *
 * Usage: ->middleware('plan.module:documents,pos')
 *
 * It checks the company plan, not the individual user's grants, so existing API integrations
 * keep working while a company can no longer use, through the API, a module the admin disabled.
 */
class RequirePlanModule
{
    public function handle(Request $request, Closure $next, string ...$modules)
    {
        $plan = TenantPlan::current()->moduleValues();

        if (array_intersect($modules, $plan)) {
            return $next($request);
        }

        return response()->json([
            'success' => false,
            'message' => 'El plan de la empresa no incluye este módulo ('.implode(' / ', $modules).').',
        ], 403);
    }
}
