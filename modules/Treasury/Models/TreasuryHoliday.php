<?php

namespace Modules\Treasury\Models;

use App\Models\Tenant\ModelTenant;

class TreasuryHoliday extends ModelTenant
{
    protected $table = 'treasury_holidays';

    protected $fillable = [
        'date',
        'name',
        'legal_reference',
        'source_url',
        'scope',
        'is_business_day_override',
    ];

    protected $casts = [
        'is_business_day_override' => 'boolean',
    ];
}
