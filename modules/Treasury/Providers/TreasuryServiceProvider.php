<?php

namespace Modules\Treasury\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Treasury\Services\TreasuryBalanceService;

class TreasuryServiceProvider extends ServiceProvider
{
    public function boot()
    {
        $this->registerConfig();
        $this->registerViews();
    }

    public function register()
    {
        $this->app->singleton(TreasuryBalanceService::class, function () {
            return new TreasuryBalanceService();
        });

        $this->app->register(RouteServiceProvider::class);
    }

    protected function registerConfig()
    {
        $this->publishes([
            __DIR__ . '/../Config/config.php' => config_path('treasury.php'),
        ], 'config');

        $this->mergeConfigFrom(
            __DIR__ . '/../Config/config.php',
            'treasury'
        );
    }

    protected function registerViews()
    {
        $viewPath = resource_path('views/modules/treasury');
        $sourcePath = __DIR__ . '/../Resources/views';

        $this->publishes([
            $sourcePath => $viewPath,
        ], 'views');

        $this->loadViewsFrom(array_merge(array_map(function ($path) {
            return $path . '/modules/treasury';
        }, \Config::get('view.paths')), [$sourcePath]), 'treasury');
    }

    public function provides()
    {
        return [TreasuryBalanceService::class];
    }
}
