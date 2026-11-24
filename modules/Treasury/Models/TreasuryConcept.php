<?php

namespace Modules\Treasury\Models;

use App\Models\Tenant\ModelTenant;

class TreasuryConcept extends ModelTenant
{
    protected $table = 'treasury_concepts';

    protected $fillable = [
        'type',
        'code',
        'description',
        'pcge_account',
        'active',
    ];

    protected $casts = [
        'active' => 'boolean',
    ];

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }
}
