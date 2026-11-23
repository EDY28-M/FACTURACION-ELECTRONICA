<?php

namespace Modules\Treasury\Models;

use App\Models\Tenant\ModelTenant;

class BankStatementLine extends ModelTenant
{
    public const STATUS_UNMATCHED = 'unmatched';
    public const STATUS_MATCHED = 'matched';
    public const STATUS_IGNORED = 'ignored';

    protected $table = 'bank_statement_lines';

    protected $fillable = [
        'bank_statement_id',
        'line_no',
        'date',
        'description',
        'reference',
        'amount',
        'status',
        'ignore_reason',
        'ignored_by',
    ];

    protected $casts = [
        'date' => 'date',
        'amount' => 'float',
        'line_no' => 'integer',
    ];

    public function statement()
    {
        return $this->belongsTo(BankStatement::class, 'bank_statement_id');
    }

    public function matches()
    {
        return $this->hasMany(BankStatementLineMatch::class);
    }

    public function activeMatches()
    {
        return $this->hasMany(BankStatementLineMatch::class)->whereNull('released_at');
    }
}
