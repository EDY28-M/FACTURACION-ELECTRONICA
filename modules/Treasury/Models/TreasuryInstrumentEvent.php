<?php

namespace Modules\Treasury\Models;

use App\Models\Tenant\ModelTenant;
use App\Models\Tenant\User;

class TreasuryInstrumentEvent extends ModelTenant
{
    protected $table = 'treasury_instrument_events';

    protected $fillable = [
        'instrument_id',
        'action',
        'from_status',
        'to_status',
        'date',
        'user_id',
        'notes',
        'movement_id',
    ];

    protected $casts = [
        'date' => 'date',
    ];

    public function instrument()
    {
        return $this->belongsTo(TreasuryInstrument::class, 'instrument_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
