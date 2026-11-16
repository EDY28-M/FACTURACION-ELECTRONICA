<?php

namespace Modules\Sale\Http\Requests;

use App\Models\Tenant\Person;
use App\Models\Tenant\Series;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ContractRequest extends FormRequest
{

    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        $rules = [
            'customer_id' => [
                'required',
            ],
            'exchange_rate_sale' => [
                'required',
                'numeric'
            ],
            'currency_type_id' => [
                'required',
            ],
            'date_of_issue' => [
                'required',
            ],
            'recurrence_enabled' => ['nullable', 'boolean'],
        ];

        // Recurrent billing fields are validated only when the recurrence is enabled.
        if ($this->boolean('recurrence_enabled')) {
            $rules += [
                'recurrence_mode' => ['required', Rule::in(['manual', 'automatic'])],
                'recurrence_period' => ['required', Rule::in(['month', 'year'])],
                'recurrence_interval' => ['required', 'integer', 'min:1', 'max:60'],
                'recurrence_start_date' => ['required', 'date'],
                'recurrence_end_date' => ['nullable', 'date', 'after_or_equal:recurrence_start_date'],
                'recurrence_day' => ['nullable', 'integer', 'min:1', 'max:31'],
                'recurrence_backfill' => ['nullable', 'boolean'],
                'billing_document_type_id' => ['required', Rule::in(['01', '03'])],
                'billing_series' => ['nullable', 'string', 'max:4'],
            ];
        }

        return $rules;
    }

    public function attributes()
    {
        return [
            'recurrence_mode' => 'modo de facturación',
            'recurrence_period' => 'periodicidad',
            'recurrence_interval' => 'intervalo',
            'recurrence_start_date' => 'fecha de inicio',
            'recurrence_end_date' => 'fecha de fin',
            'recurrence_day' => 'día de facturación',
            'billing_document_type_id' => 'tipo de comprobante',
            'billing_series' => 'serie',
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            if (!$this->boolean('recurrence_enabled')) {
                return;
            }

            // Facturas can only be issued to customers identified with RUC.
            if ($this->input('billing_document_type_id') === '01') {
                $person = Person::find($this->input('customer_id'));
                if ($person && (string) $person->identity_document_type_id !== '6') {
                    $validator->errors()->add('billing_document_type_id', 'Las facturas solo se pueden emitir a clientes con RUC. Elija boleta o cambie el cliente.');
                }
            }

            if ($this->filled('billing_series') && $this->filled('billing_document_type_id')) {
                $exists = Series::query()
                    ->where('establishment_id', $this->input('establishment_id'))
                    ->where('document_type_id', $this->input('billing_document_type_id'))
                    ->where('number', strtoupper($this->input('billing_series')))
                    ->exists();
                if (!$exists) {
                    $validator->errors()->add('billing_series', 'La serie no existe para el establecimiento y tipo de comprobante elegidos.');
                }
            }
        });
    }
}
