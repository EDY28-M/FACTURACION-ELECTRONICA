<?php

namespace App\Models\Tenant;

/**
 * One SIRE propuesta download/reconciliation request (see SireReconciliationService).
 */
class SireRequest extends ModelTenant
{
    public const STATUS_REQUESTED = 'requested';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_READY = 'ready';
    public const STATUS_FAILED = 'failed';
    public const STATUS_RECONCILED = 'reconciled';

    protected $table = 'sire_requests';

    // Fixed format: avoids resolving the tenant connection just to serialize dates (also keeps the model unit-testable).
    protected $dateFormat = 'Y-m-d H:i:s';

    protected $fillable = [
        'book', 'period', 'ticket', 'status', 'status_detail', 'file_name',
        'requested_at', 'finished_at', 'summary',
    ];

    protected $casts = [
        'summary' => 'array',
        'requested_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function isOpen(): bool
    {
        return in_array($this->status, [self::STATUS_REQUESTED, self::STATUS_PROCESSING, self::STATUS_READY], true);
    }
}
