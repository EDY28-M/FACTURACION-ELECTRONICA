<?php

namespace Modules\Treasury\Models;

use App\Models\Tenant\ModelTenant;

class BankStatementMapping extends ModelTenant
{
    protected $table = 'bank_statement_mappings';

    protected $fillable = [
        'bank_id',
        'bank_account_id',
        'name',
        'config',
        'created_by',
    ];

    protected $casts = [
        'config' => 'array',
    ];
}
