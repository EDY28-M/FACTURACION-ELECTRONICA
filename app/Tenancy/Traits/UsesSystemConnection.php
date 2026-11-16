<?php

namespace Hyn\Tenancy\Traits;

use Hyn\Tenancy\Database\Connection;

trait UsesSystemConnection
{
    public function getConnectionName()
    {
        return config('tenancy.db.system-connection-name', Connection::DEFAULT_SYSTEM_NAME);
    }
}
