<?php

namespace Modules\Treasury\Models;

use App\Models\Tenant\ModelTenant;

class TreasuryTaxRate extends ModelTenant
{
    protected $table = 'treasury_tax_rates';

    protected $fillable = [
        'tax',
        'code',
        'rate',
        'amount',
        'min_operation',
        'valid_from',
        'valid_to',
        'legal_reference',
    ];

    protected $casts = [
        'rate' => 'float',
        'amount' => 'float',
        'min_operation' => 'float',
        'valid_from' => 'date',
        'valid_to' => 'date',
    ];

    public function scopeEffectiveOn($query, string $date)
    {
        return $query
            ->where('valid_from', '<=', $date)
            ->where(function ($q) use ($date) {
                $q->whereNull('valid_to')->orWhere('valid_to', '>=', $date);
            })
            ->orderBy('valid_from', 'desc');
    }
}
