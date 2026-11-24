<?php

namespace Modules\Treasury\Models;

use App\Models\Tenant\Bank;
use App\Models\Tenant\BankAccount;
use App\Models\Tenant\Establishment;
use App\Models\Tenant\ModelTenant;
use App\Models\Tenant\Person;

/**
 * Cheque or bill of exchange (Ley 27287). Money never lives here: collecting/paying creates a TreasuryMovement.
 */
class TreasuryInstrument extends ModelTenant
{
    public const TYPE_CHEQUE_ISSUED = 'cheque_issued';
    public const TYPE_CHEQUE_RECEIVED = 'cheque_received';
    public const TYPE_BILL_PAYABLE = 'bill_payable';
    public const TYPE_BILL_RECEIVABLE = 'bill_receivable';

    public const TYPES = [
        self::TYPE_CHEQUE_RECEIVED,
        self::TYPE_CHEQUE_ISSUED,
        self::TYPE_BILL_RECEIVABLE,
        self::TYPE_BILL_PAYABLE,
    ];

    public const STATUS_ISSUED = 'issued';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_DEPOSITED = 'deposited';
    public const STATUS_COLLECTED = 'collected';
    public const STATUS_BOUNCED = 'bounced';
    public const STATUS_PROTESTED = 'protested';
    public const STATUS_VOIDED = 'voided';

    public const STATUSES = [
        self::STATUS_ISSUED,
        self::STATUS_DELIVERED,
        self::STATUS_DEPOSITED,
        self::STATUS_COLLECTED,
        self::STATUS_BOUNCED,
        self::STATUS_PROTESTED,
        self::STATUS_VOIDED,
    ];

    protected $table = 'treasury_instruments';

    protected $fillable = [
        'type',
        'number',
        'establishment_id',
        'bank_id',
        'bank_account_id',
        'person_id',
        'drawer_name',
        'payee_name',
        'issue_date',
        'due_date',
        'is_deferred',
        'amount',
        'currency_type_id',
        'status',
        'presentation_deadline',
        'protest_deadline',
        'deadline_is_estimate',
        'movement_id',
        'payment_type',
        'payment_id',
        'source_type',
        'source_id',
        'notes',
        'active_key',
        'voided_at',
        'voided_by',
        'void_reason',
        'user_id',
    ];

    protected $casts = [
        'issue_date' => 'date',
        'due_date' => 'date',
        'presentation_deadline' => 'date',
        'protest_deadline' => 'date',
        'is_deferred' => 'boolean',
        'deadline_is_estimate' => 'boolean',
        'amount' => 'float',
        'voided_at' => 'datetime',
    ];

    public function isCheque(): bool
    {
        return in_array($this->type, [self::TYPE_CHEQUE_ISSUED, self::TYPE_CHEQUE_RECEIVED], true);
    }

    public function isBill(): bool
    {
        return in_array($this->type, [self::TYPE_BILL_PAYABLE, self::TYPE_BILL_RECEIVABLE], true);
    }

    /** True when money comes IN to the company (cheque received / bill receivable). */
    public function isInflow(): bool
    {
        return in_array($this->type, [self::TYPE_CHEQUE_RECEIVED, self::TYPE_BILL_RECEIVABLE], true);
    }

    public function bank()
    {
        return $this->belongsTo(Bank::class, 'bank_id');
    }

    public function bankAccount()
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id');
    }

    public function person()
    {
        return $this->belongsTo(Person::class, 'person_id');
    }

    public function establishment()
    {
        return $this->belongsTo(Establishment::class, 'establishment_id');
    }

    public function events()
    {
        return $this->hasMany(TreasuryInstrumentEvent::class, 'instrument_id')->orderBy('id');
    }
}
