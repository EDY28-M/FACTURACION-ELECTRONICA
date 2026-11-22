<?php

namespace App\Http\Middleware;

use App\Models\Tenant\User;
use Illuminate\Auth\Middleware\Authenticate as Middleware;

class Authenticate extends Middleware
{
    protected function authenticate($request, array $guards)
    {
        parent::authenticate($request, $guards);

        // Storefront sessions and legacy customer tokens are not backoffice credentials.
        $user = $this->auth->guard()->user();
        if ($user instanceof User && $user->type === 'client') {
            abort(403, 'Esta cuenta solo puede acceder a la tienda.');
        }
    }

    /**
     * Get the path the user should be redirected to when they are not authenticated.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return string
     */
    protected function redirectTo($request)
    {
        return route('login');
    }
}
