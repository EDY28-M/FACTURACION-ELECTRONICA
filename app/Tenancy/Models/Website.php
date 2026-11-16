<?php

namespace Hyn\Tenancy\Models;

use Hyn\Tenancy\Abstracts\SystemModel;
use Hyn\Tenancy\Contracts\Website as WebsiteContract;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Website extends SystemModel implements WebsiteContract
{
    use SoftDeletes;

    protected $table = 'websites';

    protected $fillable = [
        'uuid',
        'managed_by_database_connection',
    ];

    public function hostnames(): HasMany
    {
        return $this->hasMany(config('tenancy.models.hostname', Hostname::class), 'website_id');
    }
}
