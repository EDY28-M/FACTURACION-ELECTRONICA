<?php

namespace Hyn\Tenancy;

use Hyn\Tenancy\Contracts\CurrentHostname;
use Hyn\Tenancy\Contracts\Hostname;
use Hyn\Tenancy\Contracts\Tenant;
use Hyn\Tenancy\Contracts\Website;
use Hyn\Tenancy\Database\Connection;
use Hyn\Tenancy\Models\Hostname as HostnameModel;
use Hyn\Tenancy\Models\Website as WebsiteModel;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Arr;
use Illuminate\Support\Traits\Macroable;

class Environment
{
    use Macroable;

    protected $app;
    protected $installed;

    public function __construct(Application $app)
    {
        $this->app = $app;
        $this->defaults();

        if (
            $this->installed() &&
            (!$app->runningInConsole() || $app->runningUnitTests()) &&
            config('tenancy.hostname.auto-identification', true)
        ) {
            $this->identifyHostname();
            $app->make(CurrentHostname::class);
        }
    }

    public function installed(): bool
    {
        if ($this->installed !== null) {
            return $this->installed;
        }

        try {
            $connection = $this->app->make(Connection::class)->system();
            $table = (new WebsiteModel())->getTable();
            $this->installed = $connection->getSchemaBuilder()->hasTable($table);
        } catch (\Throwable $e) {
            $this->installed = false;
        }

        return $this->installed;
    }

    public function identifyHostname()
    {
        $this->app->singleton(CurrentHostname::class, function () {
            $hostname = $this->resolveCurrentHostname();
            if ($hostname && $hostname->website) {
                $this->tenant($hostname->website);
            }
            return $hostname;
        });
    }

    protected function resolveCurrentHostname(): ?Hostname
    {
        if (!$this->app->bound('request')) {
            return null;
        }
        $request = $this->app->make('request');
        $host = $request ? $request->getHost() : null;
        if (!$host) {
            return null;
        }

        try {
            $modelClass = config('tenancy.models.hostname', HostnameModel::class);
            $hostname = $modelClass::with('website')->where('fqdn', $host)->first();
            if (!$hostname && ($default = config('tenancy.hostname.default'))) {
                $hostname = $modelClass::with('website')->where('fqdn', $default)->first();
            }
            return $hostname;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function hostname(Hostname $hostname = null): ?Hostname
    {
        if (func_num_args() > 0) {
            $this->app->instance(CurrentHostname::class, $hostname);
            $this->tenant($hostname ? $hostname->website : null);
            return $hostname;
        }

        return $this->app->make(CurrentHostname::class);
    }

    public function website(): ?Website
    {
        $tenant = $this->tenant();
        if ($tenant) {
            return $tenant;
        }
        $hostname = $this->hostname();
        return $hostname ? $hostname->website : null;
    }

    public function tenant(Website $website = null): ?Website
    {
        if (func_num_args() > 0) {
            $this->app->instance(Tenant::class, $website);
            $this->app->make(Connection::class)->set($website);
            if ($website !== null) {
                $this->activateTenantDisk($website);
            }
            return $website;
        }

        return $this->app->make(Tenant::class);
    }

    protected function activateTenantDisk(Website $website): void
    {
        $disk = config('tenancy.website.disk') ?: 'tenancy-default';
        $config = config('filesystems.disks.' . $disk, [
            'driver' => 'local',
            'root' => storage_path('app/tenancy/tenants'),
        ]);
        $tenantRoot = rtrim(Arr::get($config, 'root', storage_path('app/tenancy/tenants')), '/') . '/' . $website->uuid;
        if (config('tenancy.website.auto-create-tenant-directory', true) && !is_dir($tenantRoot)) {
            @mkdir($tenantRoot, 0755, true);
        }
        $config['root'] = $tenantRoot;
        config(['filesystems.disks.tenant' => $config]);

        if ($this->app->bound('filesystem')) {
            $filesystem = $this->app->make('filesystem');
            if (method_exists($filesystem, 'forgetDisk')) {
                $filesystem->forgetDisk('tenant');
            } elseif (method_exists($filesystem, 'set')) {
                $filesystem->set('tenant', null);
            }
        }
    }

    protected function defaults()
    {
        $empty = function () {
            return null;
        };

        $this->app->singleton(Tenant::class, $empty);
        $this->app->singleton(CurrentHostname::class, $empty);
    }
}
