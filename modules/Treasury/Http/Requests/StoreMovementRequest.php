<?php

namespace Modules\Treasury\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Treasury\Models\TreasuryMovement;
use Modules\Treasury\Services\BancarizationChecker;

class StoreMovementRequest extends FormRequest
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
            'kind' => ['required', 'string', Rule::in(TreasuryMovement::MANUAL_KINDS)],
            'direction' => ['nullable', 'integer', Rule::in([1, -1])],
            'destination_type' => ['required', 'string', Rule::in(['bank_account', 'cash'])],
            'destination_id' => ['required', 'integer', 'min:1'],
            'currency_type_id' => ['nullable', 'string', 'size:3'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:9999999999.99'],
            'exchange_rate_purchase' => ['nullable', 'numeric', 'min:0.0001', 'max:99999.9999'],
            'exchange_rate_sale' => ['nullable', 'numeric', 'min:0.0001', 'max:99999.9999'],
            'exchange_rate_used' => ['nullable', 'numeric', 'min:0.0001', 'max:99999.9999'],
            'payment_method_type_id' => ['nullable', 'string', 'max:2'],
            'sunat_payment_code' => ['nullable', 'string', Rule::in(array_keys(BancarizationChecker::SUNAT_PAYMENT_CODES))],
            'operation_number' => ['nullable', 'string', 'max:60'],
            'counterparty_person_id' => ['nullable', 'integer', 'min:1'],
            'counterparty_document' => ['nullable', 'string', 'max:20'],
            'counterparty_name' => ['nullable', 'string', 'max:255'],
            'treasury_concept_id' => ['required', 'integer', 'min:1'],
            'description' => ['required', 'string', 'max:500'],
            'low_compliance_counterparty' => ['nullable', 'boolean'],
            'bancarization_exception' => ['nullable', 'string', Rule::in(BancarizationChecker::ALLOWED_EXCEPTIONS)],
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
            'date.required' => 'La fecha del movimiento es obligatoria.',
            'date.date_format' => 'La fecha debe tener el formato YYYY-MM-DD.',
            'date.before_or_equal' => 'No se permiten movimientos con fecha futura.',
            'kind.required' => 'Seleccione el tipo de movimiento.',
            'kind.in' => 'El tipo de movimiento seleccionado no es válido.',
            'destination_type.required' => 'Seleccione si el destino es cuenta bancaria o caja.',
            'destination_id.required' => 'Seleccione una cuenta o caja.',
            'amount.required' => 'El monto es obligatorio.',
            'amount.numeric' => 'El monto debe ser un número válido.',
            'amount.min' => 'El monto mínimo permitido es 0.01.',
            'amount.max' => 'El monto excede el límite permitido.',
            'treasury_concept_id.required' => 'El concepto es obligatorio.',
            'description.required' => 'La descripción es obligatoria.',
            'bancarization_exception.in' => 'Seleccione una excepción de bancarización válida.',
        ];
    }
}
