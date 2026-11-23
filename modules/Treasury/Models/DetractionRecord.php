<?php

namespace Modules\Treasury\Models;

use App\Models\Tenant\ModelTenant;

class DetractionRecord extends ModelTenant
{
    public const SIDE_RECEIVABLE = 'receivable';
    public const SIDE_PAYABLE = 'payable';

    public const STATUS_PENDING = 'pending';
    public const STATUS_DEPOSITED = 'deposited';
    public const STATUS_RECEIVED = 'received';
    // Marker for sources whose detraction data is unparseable; keeps the lazy sync from re-reading them.
    public const STATUS_SKIPPED = 'skipped';
    // Computed only (pending + due_date in the past); never persisted.
    public const STATUS_OVERDUE = 'overdue';

    protected $table = 'detraction_records';

    protected $fillable = [
        'side',
        'source_type',
        'source_id',
        'establishment_id',
        'user_id',
        'code',
        'rate',
        'currency_type_id',
        'base_amount',
        'amount_pen',
        'bn_account_number',
        'registry_date',
        'due_date',
        'deposited_at',
        'constancia_number',
        'operation_number',
        'deposit_movement_id',
        'status',
    ];

    protected $casts = [
        'rate' => 'float',
        'base_amount' => 'float',
        'amount_pen' => 'float',
        'registry_date' => 'date:Y-m-d',
        'due_date' => 'date:Y-m-d',
        'deposited_at' => 'date:Y-m-d',
    ];

    public function deposit_movement()
    {
        return $this->belongsTo(TreasuryMovement::class, 'deposit_movement_id');
    }

    /**
     * Pending records whose due date already passed are reported as overdue without persisting it.
     */
    public function effectiveStatus(?string $asOf = null, int $graceDays = 0): string
    {
        if ($this->status !== self::STATUS_PENDING) {
            return (string) $this->status;
        }

        $asOf = $asOf ?: date('Y-m-d');
        $cutoff = $graceDays > 0 ? date('Y-m-d', strtotime($asOf . ' -' . $graceDays . ' days')) : $asOf;
        $due = $this->due_date ? $this->due_date->format('Y-m-d') : null;

        return ($due !== null && $due < $cutoff) ? self::STATUS_OVERDUE : self::STATUS_PENDING;
    }
}
