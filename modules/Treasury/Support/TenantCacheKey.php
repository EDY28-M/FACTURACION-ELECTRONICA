<?php

namespace Modules\Treasury\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Prefix for in-memory caches held by Treasury services (schema probes, rates...).
 *
 * The services are container singletons; in a long-lived worker (queue, Octane) the same instance can serve several
 * tenants, so any per-tenant cached fact must be keyed by the tenant database name or one tenant would read another
 * tenant's cached answer.
 */
class TenantCacheKey
{
    public static function prefix(): string
    {
        try {
            if (!Model::getConnectionResolver()) {
                return '';
            }

            return (string) DB::connection('tenant')->getDatabaseName() . '|';
        } catch (\Throwable $e) {
            return '';
        }
    }
}
