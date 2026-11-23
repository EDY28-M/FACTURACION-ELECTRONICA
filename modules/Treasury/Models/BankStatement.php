<?php

namespace Modules\Treasury\Models;

use App\Models\Tenant\BankAccount;
use App\Models\Tenant\ModelTenant;
use App\Models\Tenant\User;

class BankStatement extends ModelTenant
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_CLOSED = 'closed';

    protected $table = 'bank_statements';

    protected $fillable = [
        'bank_account_id',
        'period_from',
        'period_to',
        'opening_balance',
        'closing_balance',
        'file_name',
        'file_path',
        'imported_by',
        'status',
        'closed_at',
        'closed_by',
        'snapshot',
        'imported_unbalanced',
        'unbalanced_gap',
    ];

    protected $casts = [
        'period_from' => 'date',
        'period_to' => 'date',
        'opening_balance' => 'float',
        'closing_balance' => 'float',
        'closed_at' => 'datetime',
        'snapshot' => 'array',
        'imported_unbalanced' => 'boolean',
        'unbalanced_gap' => 'float',
    ];

    public function bank_account()
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function lines()
    {
        return $this->hasMany(BankStatementLine::class);
    }

    public function importer()
    {
        return $this->belongsTo(User::class, 'imported_by');
    }

    public function isClosed(): bool
    {
        return $this->status === self::STATUS_CLOSED;
    }
}
