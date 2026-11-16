<?php

namespace Hyn\Tenancy\Contracts;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

interface Hostname extends CurrentHostname
{
    public function website(): BelongsTo;
}
