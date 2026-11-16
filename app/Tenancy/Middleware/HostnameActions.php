<?php

namespace Hyn\Tenancy\Middleware;

use Closure;

class HostnameActions
{
    public function handle($request, Closure $next)
    {
        return $next($request);
    }
}
