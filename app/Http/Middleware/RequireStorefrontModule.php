<?php

namespace App\Http\Middleware;

use App\Support\Access\TenantPlan;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Public storefront guard: the tenant plan must include the given module (default "ecommerce").
 * When it does not, the store does not exist for visitors: 404, never a hint that it is disabled.
 *
 * Usage: ->middleware('plan.storefront')  or  ->middleware('plan.storefront:ecommerce')
 */
class RequireStorefrontModule
{
    public function handle(Request $request, Closure $next, string $module = 'ecommerce')
    {
        if (! in_array($module, TenantPlan::current()->moduleValues(), true)) {
            throw new NotFoundHttpException();
        }

        return $next($request);
    }
}
