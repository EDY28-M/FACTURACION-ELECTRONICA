<?php

namespace Hyn\Tenancy\Traits;

use Hyn\Tenancy\Database\Connection;

trait UsesTenantConnection
{
    public function getConnectionName()
    {
        return config('tenancy.db.tenant-connection-name', Connection::DEFAULT_TENANT_NAME);
    }
}
