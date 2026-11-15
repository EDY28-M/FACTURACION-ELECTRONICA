<?php

namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PurchaseRequest extends FormRequest
{

    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'supplier_id' => [
                'required',
            ],
            'number' => [
                'required',
                'numeric'
            ],
            'series' => [
                'required',
            ],
            'date_of_issue' => [
                'required',
            ],
            'sire_destination' => [
                'nullable',
                Rule::in(['DG', 'DGNG', 'DNG']),
            ],
            'affected_document_type_id' => ['nullable', 'string', 'max:2'],
            'affected_series' => ['nullable', 'string', 'max:20'],
            'affected_number' => ['nullable', 'string', 'max:20'],
            'affected_date_of_issue' => ['nullable', 'date'],
        ];
    }
}
