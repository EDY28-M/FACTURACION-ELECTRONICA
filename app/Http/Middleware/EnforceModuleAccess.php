<?php

namespace App\Http\Middleware;

use App\Support\Access\AccessMap;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Single access check for modules and levels (see config/permissions.php).
 * Registered under both "redirect.module" and "redirect.level" so either alias enforces everything.
 */
class EnforceModuleAccess
{
    public function handle($request, Closure $next)
    {
        $user = $request->user();

        // Authentication is the job of the "auth" middleware.
        if (!$user) {
            return $next($request);
        }

        $map = AccessMap::fromConfig();

        // The owner is capped by the tenant plan like everyone else (moduleValues() is already effective).
        $decision = $map->decide(
            $request->path(),
            false,
            $user->moduleValues(),
            $user->levelValues(),
            $this->wantsJson($request),
            $request->method()
        );

        if ($decision['allowed']) {
            if ($decision['reason'] === 'unmapped') {
                Log::debug('access: path without permission rule', ['path' => $request->path()]);
            }

            return $next($request);
        }

        return $this->deny($request, $user, $map);
    }

    private function wantsJson(Request $request): bool
    {
        if ($request->hasHeader('X-Tenant-Shell')) {
            return false;
        }

        return $request->ajax() || $request->expectsJson();
    }

    private function deny(Request $request, $user, AccessMap $map)
    {
        $message = config('permissions.denied_message');

        if ($this->wantsJson($request)) {
            return response()->json(['message' => $message], 403);
        }

        if ($request->isMethod('GET') || $request->isMethod('HEAD')) {
            $target = $map->firstAllowedPath($user->moduleValues(), $user->levelValues());

            if ($target !== null) {
                $path = trim($request->path(), '/');
                $redirect = redirect('/'.$target);

                return ($path === '' || $path === 'dashboard')
                    ? $redirect
                    : $redirect->with('error', $message);
            }
        }

        return response()->view('tenant.errors.no_access', ['message' => $message], 403);
    }
}
