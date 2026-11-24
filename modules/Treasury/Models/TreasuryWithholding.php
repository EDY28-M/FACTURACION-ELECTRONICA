<?php

namespace Modules\Treasury\Models;

use App\Models\Tenant\ModelTenant;

class TreasuryWithholding extends ModelTenant
{
    public const KIND_RETENTION_RECEIVED = 'retention_received';
    public const KIND_RETENTION_EMITTED = 'retention_emitted';
    public const KIND_PERCEPTION_EMITTED = 'perception_emitted';

    public const KINDS = [
        self::KIND_RETENTION_RECEIVED,
        self::KIND_RETENTION_EMITTED,
        self::KIND_PERCEPTION_EMITTED,
    ];

    public const STATE_ACTIVE = 'active';
    public const STATE_VOIDED = 'voided';

    protected $table = 'treasury_withholdings';

    protected $fillable = [
        'kind',
        'certificate_type',
        'series',
        'number',
        'date',
        'establishment_id',
        'user_id',
        'source_type',
        'source_id',
        'parent_type',
        'parent_id',
        'retention_id',
        'perception_id',
        'active_link_key',
        'cert_active_key',
        'person_id',
        'counterparty_document',
        'counterparty_name',
        'currency_type_id',
        'base_amount',
        'rate',
        'amount',
        'amount_pen',
        'payable_to_sunat',
        'settled_movement_id',
        'state',
        'voided_at',
        'voided_by',
        'void_reason',
        'notes',
    ];

    protected $casts = [
        'date' => 'date:Y-m-d',
        'base_amount' => 'float',
        'rate' => 'float',
        'amount' => 'float',
        'amount_pen' => 'float',
        'payable_to_sunat' => 'boolean',
        'voided_at' => 'datetime',
    ];

    public function scopeActive($query)
    {
        return $query->where('state', self::STATE_ACTIVE);
    }
}
