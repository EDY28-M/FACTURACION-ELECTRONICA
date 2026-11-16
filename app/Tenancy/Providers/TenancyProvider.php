<?php

namespace Hyn\Tenancy\Providers;

use Hyn\Tenancy\Commands\MigrateCommand;
use Hyn\Tenancy\Contracts\CurrentHostname;
use Hyn\Tenancy\Contracts\Hostname as HostnameContract;
use Hyn\Tenancy\Contracts\Repositories\HostnameRepository as HostnameRepositoryContract;
use Hyn\Tenancy\Contracts\Repositories\WebsiteRepository as WebsiteRepositoryContract;
use Hyn\Tenancy\Contracts\Tenant;
use Hyn\Tenancy\Contracts\Website as WebsiteContract;
use Hyn\Tenancy\Database\Connection;
use Hyn\Tenancy\Environment;
use Hyn\Tenancy\Models\Hostname;
use Hyn\Tenancy\Models\Website;
use Hyn\Tenancy\Repositories\HostnameRepository;
use Hyn\Tenancy\Repositories\WebsiteRepository;
use Illuminate\Support\ServiceProvider;

class TenancyProvider extends ServiceProvider
{
    public function register()
    {
        $this->mergeConfigFrom(config_path('tenancy.php'), 'tenancy');

        $this->app['config']->set('filesystems.disks.tenancy-default', [
            'driver' => 'local',
            'root' => storage_path('app/tenancy/tenants'),
        ]);

        $this->app->bind(WebsiteContract::class, function ($app) {
            $class = $app['config']->get('tenancy.models.website', Website::class);
            return new $class();
        });

        $this->app->bind(HostnameContract::class, function ($app) {
            $class = $app['config']->get('tenancy.models.hostname', Hostname::class);
            return new $class();
        });

        $this->app->singleton(Connection::class);
        $this->app->singleton(WebsiteRepositoryContract::class, WebsiteRepository::class);
        $this->app->singleton(HostnameRepositoryContract::class, HostnameRepository::class);

        $this->app->singleton(Tenant::class, function () {
            return null;
        });

        $this->app->singleton(CurrentHostname::class, function () {
            return null;
        });

        $this->app->singleton(Environment::class, function ($app) {
            return new Environment($app);
        });

        $this->commands([
            MigrateCommand::class,
        ]);
    }

    public function boot()
    {
        $this->app->make(Environment::class);
    }
}
