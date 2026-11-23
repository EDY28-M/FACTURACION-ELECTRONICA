<?php

namespace Modules\Ecommerce\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The module namespace to assume when generating URLs to actions.
     *
     * @var string
     */
    protected $moduleNamespace = 'Modules\Ecommerce\Http\Controllers';

    /**
     * Called before routes are registered.
     *
     * Register any model bindings or pattern based filters.
     *
     * @return void
     */
    public function boot()
    {
        $this->configureRateLimiting();

        parent::boot();
    }

    /**
     * Independent counters per action: login attempts must not consume the registration or
     * checkout budget (and vice versa). Login is keyed by IP + email so one noisy address does
     * not lock a different account.
     */
    protected function configureRateLimiting()
    {
        RateLimiter::for('ecommerce-login', function (Request $request) {
            return Limit::perMinute(5)->by('ecommerce-login|'.$request->ip().'|'.strtolower((string) $request->input('email')));
        });
        RateLimiter::for('ecommerce-register', function (Request $request) {
            return Limit::perMinute(5)->by('ecommerce-register|'.$request->ip());
        });
        RateLimiter::for('ecommerce-checkout', function (Request $request) {
            return Limit::perMinute(10)->by('ecommerce-checkout|'.($request->user() ? 'u'.$request->user()->id : $request->ip()));
        });
    }

    /**
     * Define the routes for the application.
     *
     * @return void
     */
    public function map()
    {
        $this->mapApiRoutes();

        $this->mapWebRoutes();
    }

    /**
     * Define the "web" routes for the application.
     *
     * These routes all receive session state, CSRF protection, etc.
     *
     * @return void
     */
    protected function mapWebRoutes()
    {
        Route::middleware('web')
            ->namespace($this->moduleNamespace)
            ->group(__DIR__ . '/../Routes/web.php');
    }

    /**
     * Define the "api" routes for the application.
     *
     * These routes are typically stateless.
     *
     * @return void
     */
    protected function mapApiRoutes()
    {
        Route::prefix('api')
            ->middleware('api')
            ->namespace($this->moduleNamespace)
            ->group(__DIR__ . '/../Routes/api.php');
    }
}
