<?php

namespace Hyn\Tenancy\Database;

use Hyn\Tenancy\Contracts\Hostname;
use Hyn\Tenancy\Contracts\Website;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Arr;
use Illuminate\Support\Traits\Macroable;

class Connection
{
    use Macroable;

    const DEFAULT_SYSTEM_NAME = 'system';
    const DEFAULT_TENANT_NAME = 'tenant';
    const DEFAULT_MIGRATION_NAME = 'tenant-migration';

    const DIVISION_MODE_SEPARATE_DATABASE = 'database';
    const DIVISION_MODE_SEPARATE_PREFIX = 'prefix';
    const DIVISION_MODE_SEPARATE_SCHEMA = 'schema';
    const DIVISION_MODE_BYPASS = 'bypass';

    protected $config;
    protected $db;
    protected $artisan;

    public function __construct(Config $config, DatabaseManager $db, Kernel $artisan)
    {
        $this->config = $config;
        $this->db = $db;
        $this->artisan = $artisan;

        if ($default = $this->config->get('tenancy.db.default')) {
            $this->config->set('database.default', $default);
        }
    }

    public function get(): \Illuminate\Database\Connection
    {
        return $this->db->connection($this->tenantName());
    }

    public function exists(string $connection = null): bool
    {
        $connection = $connection ?? $this->tenantName();
        return Arr::has($this->db->getConnections(), $connection);
    }

    public function set($to = null, $connection = null): bool
    {
        $connection = $connection ?? $this->tenantName();
        $website = $to instanceof Hostname ? $to->website : $to;

        if (!$website) {
            $this->purge($connection);
            return false;
        }

        $existing = $this->configuration($connection);

        $this->config->set(
            sprintf('database.connections.%s', $connection),
            $this->generateConfigurationArray($website)
        );

        if (Arr::get($existing, 'uuid') === $website->uuid) {
            return true;
        }

        $this->db->purge($connection);
        $this->db->reconnect($connection);

        return true;
    }

    public function configuration(string $connection = null): array
    {
        $connection = $connection ?? $this->tenantName();
        return $this->config->get(sprintf('database.connections.%s', $connection), []);
    }

    public function system($for = null): \Illuminate\Database\Connection
    {
        $website = $for instanceof Hostname ? $for->website : $for;
        return $this->db->connection(
            $website && !empty($website->managed_by_database_connection)
                ? $website->managed_by_database_connection
                : $this->systemName()
        );
    }

    public function systemName(): string
    {
        return $this->config->get('tenancy.db.system-connection-name', static::DEFAULT_SYSTEM_NAME);
    }

    public function tenantName(): string
    {
        return $this->config->get('tenancy.db.tenant-connection-name', static::DEFAULT_TENANT_NAME);
    }

    public function purge($connection = null)
    {
        $connection = $connection ?? $this->tenantName();
        $this->db->purge($connection);
        $this->config->set(sprintf('database.connections.%s', $connection), []);
    }

    public function migrate($for, string $path = null): bool
    {
        $website = $for instanceof Hostname ? $for->website : $for;
        if (!$website) {
            return false;
        }
        $this->set($website);

        $options = [
            '--database' => $this->tenantName(),
            '--force' => true,
        ];
        $migrationPath = $path ?: $this->config->get('tenancy.db.tenant-migrations-path');
        if ($migrationPath && realpath($migrationPath)) {
            $options['--path'] = realpath($migrationPath);
            $options['--realpath'] = true;
        }

        return $this->artisan->call('migrate', $options) === 0;
    }

    public function seed($for, string $class = null): bool
    {
        $website = $for instanceof Hostname ? $for->website : $for;
        if (!$website) {
            return false;
        }
        $this->set($website);

        $seedClass = $class ?: $this->config->get('tenancy.db.tenant-seed-class');
        if (!$seedClass || !class_exists($seedClass)) {
            return true;
        }

        $fqcn = '\\' . ltrim($seedClass, '\\');

        return $this->artisan->call('db:seed', [
            '--database' => $this->tenantName(),
            '--class' => $fqcn,
            '--force' => true,
        ]) === 0;
    }

    public function generateConfigurationArray(Website $website): array
    {
        $clone = $this->config->get(sprintf(
            'database.connections.%s',
            $website->managed_by_database_connection ?? $this->systemName()
        ), []);

        $mode = $this->config->get('tenancy.db.tenant-division-mode', static::DIVISION_MODE_SEPARATE_DATABASE);
        $clone['uuid'] = $website->uuid;

        if ($mode === static::DIVISION_MODE_SEPARATE_PREFIX) {
            $clone['prefix'] = sprintf('%d_', $website->id);
        } else {
            $clone['database'] = $website->uuid;
        }

        return $clone;
    }
}
