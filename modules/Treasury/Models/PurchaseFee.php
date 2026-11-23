<?php

namespace Modules\Treasury\Models;

use App\Models\Tenant\ModelTenant;
use App\Models\Tenant\Purchase;
use Hyn\Tenancy\Traits\UsesTenantConnection;

class PurchaseFee extends ModelTenant
{
    use UsesTenantConnection;

    protected $table = 'purchase_fees';

    protected $fillable = [
        'purchase_id',
        'date',
        'currency_type_id',
        'amount',
    ];

    protected $casts = [
        'date' => 'date',
        'amount' => 'float',
    ];

    public function purchase()
    {
        return $this->belongsTo(Purchase::class, 'purchase_id');
    }
}
