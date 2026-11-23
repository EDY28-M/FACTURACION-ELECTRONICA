<?php

namespace Modules\Treasury\Models;

use App\Models\Tenant\Bank;
use App\Models\Tenant\BankAccount;
use App\Models\Tenant\ModelTenant;

class PaymentEvidence extends ModelTenant
{
    protected $table = 'payment_evidences';

    protected $fillable = [
        'payable_type',
        'payable_id',
        'sunat_payment_code',
        'operation_number',
        'bank_id',
        'bank_account_id',
        'operation_date',
        'payer_or_beneficiary_document',
        'payer_or_beneficiary_name',
        'third_party_payment',
        'third_party_reported_to_sunat',
        'bancarization_exception',
    ];

    protected $casts = [
        'operation_date' => 'date',
        'third_party_payment' => 'boolean',
        'third_party_reported_to_sunat' => 'boolean',
    ];

    public function payable()
    {
        return $this->morphTo();
    }

    public function bank()
    {
        return $this->belongsTo(Bank::class);
    }

    public function bank_account()
    {
        return $this->belongsTo(BankAccount::class);
    }
}
