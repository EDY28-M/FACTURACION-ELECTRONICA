<?php

namespace Modules\Treasury\Models;

use App\Models\Tenant\Cash;
use App\Models\Tenant\Establishment;
use App\Models\Tenant\ModelTenant;
use App\Models\Tenant\User;

class TreasuryCashCount extends ModelTenant
{
    protected $table = 'treasury_cash_counts';

    protected $fillable = [
        'cash_id',
        'establishment_id',
        'counted_at',
        'user_id',
        'system_balance',
        'counted_balance',
        'difference',
        'denominations',
        'notes',
    ];

    protected $casts = [
        'counted_at' => 'datetime',
        'system_balance' => 'float',
        'counted_balance' => 'float',
        'difference' => 'float',
        'denominations' => 'array',
    ];

    public function cash()
    {
        return $this->belongsTo(Cash::class, 'cash_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function establishment()
    {
        return $this->belongsTo(Establishment::class, 'establishment_id');
    }
}
