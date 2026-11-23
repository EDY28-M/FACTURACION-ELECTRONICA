<?php

namespace Modules\Treasury\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Treasury\Services\BancarizationChecker;

class StoreTransferRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        $dateRules = ['required', 'date_format:Y-m-d'];
        if (!config('treasury.allow_future_dates', false)) {
            $dateRules[] = 'before_or_equal:today';
        }

        return [
            'establishment_id' => ['nullable', 'integer', 'min:1'],
            'date' => $dateRules,
            'origin_type' => ['required', 'string', Rule::in(['bank_account', 'cash'])],
            'origin_id' => ['required', 'integer', 'min:1'],
            'target_type' => ['required', 'string', Rule::in(['bank_account', 'cash'])],
            'target_id' => ['required', 'integer', 'min:1'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:9999999999.99'],
            'target_amount' => ['nullable', 'numeric', 'min:0.01', 'max:9999999999.99'],
            'exchange_rate_purchase' => ['nullable', 'numeric', 'min:0.0001', 'max:99999.9999'],
            'exchange_rate_sale' => ['nullable', 'numeric', 'min:0.0001', 'max:99999.9999'],
            'exchange_rate_used' => ['nullable', 'numeric', 'min:0.0001', 'max:99999.9999'],
            'payment_method_type_id' => ['nullable', 'string', 'max:2'],
            'sunat_payment_code' => ['nullable', 'string', Rule::in(array_keys(BancarizationChecker::SUNAT_PAYMENT_CODES))],
            'operation_number' => ['nullable', 'string', 'max:60'],
            'description' => ['required', 'string', 'max:500'],
            'same_holder' => ['nullable', 'boolean'],
            'include_itf' => ['nullable', 'boolean'],
            'itf_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'bank_fee_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'bank_fee_description' => ['nullable', 'string', 'max:500'],
            'evidence' => ['nullable', 'array'],
        ];
    }

    public function messages()
    {
        return [
            'date.required' => 'La fecha de la transferencia es obligatoria.',
            'date.date_format' => 'La fecha debe tener el formato YYYY-MM-DD.',
            'date.before_or_equal' => 'No se permiten transferencias con fecha futura.',
            'origin_type.required' => 'Seleccione el tipo de cuenta de origen.',
            'origin_id.required' => 'Seleccione la cuenta o caja de origen.',
            'target_type.required' => 'Seleccione el tipo de cuenta de destino.',
            'target_id.required' => 'Seleccione la cuenta o caja de destino.',
            'amount.required' => 'El monto a transferir es obligatorio.',
            'amount.numeric' => 'El monto debe ser un número válido.',
            'amount.min' => 'El monto mínimo permitido es 0.01.',
            'amount.max' => 'El monto excede el límite permitido.',
            'target_amount.min' => 'El monto de destino debe ser mayor o igual a 0.01.',
            'description.required' => 'La descripción de la transferencia es obligatoria.',
        ];
    }
}
