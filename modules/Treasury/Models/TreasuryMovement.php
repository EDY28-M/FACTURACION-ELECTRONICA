<?php

namespace Modules\Treasury\Models;

use App\Models\Tenant\Establishment;
use App\Models\Tenant\ModelTenant;
use App\Models\Tenant\PaymentMethodType;
use App\Models\Tenant\Person;
use App\Models\Tenant\User;

class TreasuryMovement extends ModelTenant
{
    public const STATE_ACTIVE = 'active';
    public const STATE_VOIDED = 'voided';

    public const DIRECTION_INFLOW = 1;
    public const DIRECTION_OUTFLOW = -1;

    public const INFLOW_KINDS = [
        'income',
        'transfer_in',
        'interest',
        'detraction_release',
    ];

    public const OUTFLOW_KINDS = [
        'expense',
        'transfer_out',
        'itf',
        'bank_fee',
        'detraction_deposit',
        'tax_payment',
    ];

    public const MANUAL_KINDS = [
        'income',
        'expense',
        'interest',
        'bank_fee',
        'tax_payment',
        'adjustment',
    ];

    public const TRANSFER_KINDS = [
        'transfer_out',
        'transfer_in',
    ];

    public const ALL_KINDS = [
        'income',
        'expense',
        'transfer_out',
        'transfer_in',
        'itf',
        'bank_fee',
        'interest',
        'adjustment',
        'detraction_deposit',
        'detraction_release',
        'tax_payment',
    ];

    protected $table = 'treasury_movements';

    protected $fillable = [
        'establishment_id',
        'date',
        'kind',
        'direction',
        'destination_type',
        'destination_id',
        'currency_type_id',
        'amount',
        'exchange_rate_purchase',
        'exchange_rate_sale',
        'exchange_rate_used',
        'amount_pen',
        'payment_method_type_id',
        'sunat_payment_code',
        'operation_number',
        'counterparty_person_id',
        'counterparty_document',
        'counterparty_name',
        'treasury_concept_id',
        'description',
        'transfer_group',
        'related_movement_id',
        'source_type',
        'source_id',
        'reconciled_at',
        'bank_statement_line_id',
        'state',
        'voided_at',
        'voided_by',
        'void_reason',
        'user_id',
    ];

    protected $casts = [
        'date' => 'date',
        'direction' => 'integer',
        'amount' => 'float',
        'exchange_rate_purchase' => 'float',
        'exchange_rate_sale' => 'float',
        'exchange_rate_used' => 'float',
        'amount_pen' => 'float',
        'reconciled_at' => 'datetime',
        'voided_at' => 'datetime',
    ];

    public function scopeActive($query)
    {
        return $query->where('state', self::STATE_ACTIVE);
    }

    public function destination()
    {
        return $this->morphTo();
    }

    public function source()
    {
        return $this->morphTo();
    }

    public function establishment()
    {
        return $this->belongsTo(Establishment::class);
    }

    public function concept()
    {
        return $this->belongsTo(TreasuryConcept::class, 'treasury_concept_id');
    }

    public function payment_method_type()
    {
        return $this->belongsTo(PaymentMethodType::class, 'payment_method_type_id');
    }

    public function counterparty()
    {
        return $this->belongsTo(Person::class, 'counterparty_person_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function evidence()
    {
        return $this->morphOne(PaymentEvidence::class, 'payable');
    }

    public function related_movement()
    {
        return $this->belongsTo(self::class, 'related_movement_id');
    }

    public function child_movements()
    {
        return $this->hasMany(self::class, 'related_movement_id');
    }

    public function voided_by_user()
    {
        return $this->belongsTo(User::class, 'voided_by');
    }
}
