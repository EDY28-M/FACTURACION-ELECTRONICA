<?php

namespace Modules\Sale\Models;

use App\Models\Tenant\Document;
use App\Models\Tenant\ModelTenant;

/**
 * One billed period of a recurrent contract. UNIQUE(contract_id, period_date) guarantees a period is billed at most once.
 */
class ContractBilling extends ModelTenant
{
    const STATUS_ISSUED = 'issued';
    const STATUS_FAILED = 'failed';

    protected $fillable = [
        'contract_id',
        'period_date',
        'document_id',
        'status',
        'error',
        'mode',
        'attempts',
    ];

    protected $casts = [
        'period_date' => 'date',
    ];

    public function contract()
    {
        return $this->belongsTo(Contract::class);
    }

    public function document()
    {
        return $this->belongsTo(Document::class);
    }
}
