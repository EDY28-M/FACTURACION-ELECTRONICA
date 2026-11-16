<?php

namespace Hyn\Tenancy\Middleware;

use Closure;
use Hyn\Tenancy\Contracts\CurrentHostname;
use Hyn\Tenancy\Environment;

class EagerIdentification
{
    public function handle($request, Closure $next)
    {
        $env = app(Environment::class);
        if ($env->installed()) {
            app(CurrentHostname::class);
        }
        return $next($request);
    }
}
