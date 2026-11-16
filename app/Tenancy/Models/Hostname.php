<?php

namespace Hyn\Tenancy\Models;

use Hyn\Tenancy\Abstracts\SystemModel;
use Hyn\Tenancy\Contracts\Hostname as HostnameContract;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Hostname extends SystemModel implements HostnameContract
{
    use SoftDeletes;

    protected $table = 'hostnames';

    protected $fillable = [
        'fqdn',
        'redirect_to',
        'force_https',
        'under_maintenance_since',
        'website_id',
    ];

    protected $casts = [
        'force_https' => 'boolean',
        'under_maintenance_since' => 'datetime',
    ];

    public function website(): BelongsTo
    {
        return $this->belongsTo(config('tenancy.models.website', Website::class), 'website_id');
    }
}
