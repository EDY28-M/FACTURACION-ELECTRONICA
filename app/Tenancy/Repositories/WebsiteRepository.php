<?php

namespace Hyn\Tenancy\Repositories;

use Hyn\Tenancy\Contracts\Repositories\WebsiteRepository as Contract;
use Hyn\Tenancy\Contracts\Website;
use Hyn\Tenancy\Database\Connection;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class WebsiteRepository implements Contract
{
    protected $website;
    protected $cache;
    protected $connection;

    public function __construct(Website $website, CacheFactory $cache, Connection $connection)
    {
        $this->website = $website;
        $this->cache = $cache;
        $this->connection = $connection;
    }

    public function findByUuid(string $uuid)
    {
        return $this->query()->where('uuid', $uuid)->first();
    }

    public function findById($id)
    {
        return $this->query()->find($id);
    }

    public function create(Website &$website): Website
    {
        if ($website->exists) {
            return $this->update($website);
        }

        if (empty($website->uuid) && !config('tenancy.website.disable-random-id', false)) {
            $uuid = str_replace('-', '', (string) Str::uuid());
            $website->uuid = config('tenancy.website.uuid-limit-length-to-32', false)
                ? substr($uuid, 0, 32)
                : $uuid;
        }

        $website->save();
        $this->cache->forget("tenancy.website.{$website->uuid}");

        if (config('tenancy.website.auto-create-tenant-directory', true)) {
            $dir = storage_path('app/tenancy/tenants/' . $website->uuid);
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
        }

        if (config('tenancy.db.auto-create-tenant-database', true)) {
            $this->createTenantDatabase($website);
        }

        if (config('tenancy.db.tenant-migrations-path')) {
            $this->connection->migrate($website);
            if (config('tenancy.db.tenant-seed-class')) {
                $this->connection->seed($website);
            }
        }

        return $website;
    }

    public function update(Website &$website): Website
    {
        if (!$website->exists) {
            return $this->create($website);
        }

        $website->save();
        $this->cache->forget("tenancy.website.{$website->uuid}");

        return $website;
    }

    public function delete(Website &$website, $hard = false): Website
    {
        $uuid = $website->uuid;
        $hard ? $website->forceDelete() : $website->delete();
        $this->cache->forget("tenancy.website.{$uuid}");

        if ($hard || config('tenancy.db.auto-delete-tenant-database', false)) {
            $this->dropTenantDatabase($website);
        }

        return $website;
    }

    protected function createTenantDatabase(Website $website): void
    {
        if (config('tenancy.db.tenant-division-mode', Connection::DIVISION_MODE_SEPARATE_DATABASE) !== Connection::DIVISION_MODE_SEPARATE_DATABASE) {
            return;
        }

        $sysConn = $this->connection->system($website);
        $config = $this->connection->generateConfigurationArray($website);
        $rawDbName = (string) ($config['database'] ?? $website->uuid);
        $systemDbName = (string) $sysConn->getDatabaseName();
        if ($rawDbName === '' || $rawDbName === $systemDbName) {
            throw new \RuntimeException('Refusing to create tenant database with empty or system database name.');
        }

        $dbName = str_replace('`', '``', $rawDbName);
        $charset = preg_replace('/[^a-zA-Z0-9_]/', '', (string) ($config['charset'] ?? 'utf8mb4')) ?: 'utf8mb4';
        $collation = preg_replace('/[^a-zA-Z0-9_]/', '', (string) ($config['collation'] ?? 'utf8mb4_unicode_ci')) ?: 'utf8mb4_unicode_ci';

        $sysConn->statement("CREATE DATABASE IF NOT EXISTS `{$dbName}` DEFAULT CHARACTER SET {$charset} DEFAULT COLLATE {$collation}");
    }

    protected function dropTenantDatabase(Website $website): void
    {
        if (config('tenancy.db.tenant-division-mode', Connection::DIVISION_MODE_SEPARATE_DATABASE) !== Connection::DIVISION_MODE_SEPARATE_DATABASE) {
            return;
        }

        $sysConn = $this->connection->system($website);
        $config = $this->connection->generateConfigurationArray($website);
        $rawDbName = (string) ($config['database'] ?? $website->uuid);
        $systemDbName = (string) $sysConn->getDatabaseName();
        if ($rawDbName === '' || $rawDbName === $systemDbName) {
            throw new \RuntimeException('Refusing to drop system or empty database.');
        }

        $dbName = str_replace('`', '``', $rawDbName);

        $sysConn->statement("DROP DATABASE IF EXISTS `{$dbName}`");
    }

    public function query(): Builder
    {
        return $this->website->newQuery();
    }
}
