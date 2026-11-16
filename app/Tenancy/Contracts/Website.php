<?php

namespace Hyn\Tenancy\Contracts;

use Illuminate\Database\Eloquent\Relations\HasMany;

interface Website extends Tenant
{
    public function hostnames(): HasMany;
}
