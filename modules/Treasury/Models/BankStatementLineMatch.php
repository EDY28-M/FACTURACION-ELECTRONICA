<?php

namespace Modules\Treasury\Models;

use App\Models\Tenant\ModelTenant;

class BankStatementLineMatch extends ModelTenant
{
    protected $table = 'bank_statement_line_matches';

    protected $fillable = [
        'bank_statement_line_id',
        'matchable_type',
        'matchable_id',
        'amount',
        'item_date',
        'active_match_key',
        'matched_by',
        'released_at',
        'released_by',
    ];

    protected $casts = [
        'amount' => 'float',
        'item_date' => 'date',
        'released_at' => 'datetime',
    ];

    public static function keyFor(string $type, int $id): string
    {
        return $type . ':' . $id;
    }

    public function line()
    {
        return $this->belongsTo(BankStatementLine::class, 'bank_statement_line_id');
    }

    public function matchable()
    {
        return $this->morphTo();
    }

    public function scopeActive($query)
    {
        return $query->whereNull('released_at');
    }
}
