<?php

namespace Modules\Treasury\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Treasury\Services\BancarizationChecker;

class StorePaymentEvidenceRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'sunat_payment_code' => ['required', 'string', Rule::in(array_keys(BancarizationChecker::SUNAT_PAYMENT_CODES))],
            'operation_number' => ['nullable', 'string', 'max:60'],
            'bank_id' => ['nullable', 'integer', 'min:1'],
            'bank_account_id' => ['nullable', 'integer', 'min:1'],
            'operation_date' => ['required', 'date_format:Y-m-d'],
            'payer_or_beneficiary_document' => ['nullable', 'string', 'max:20'],
            'payer_or_beneficiary_name' => ['nullable', 'string', 'max:255'],
            'third_party_payment' => ['nullable', 'boolean'],
            'third_party_reported_to_sunat' => ['nullable', 'boolean'],
            'bancarization_exception' => ['nullable', 'string', Rule::in(BancarizationChecker::ALLOWED_EXCEPTIONS)],
            'low_compliance_counterparty' => ['nullable', 'boolean'],
        ];
    }

    public function messages()
    {
        return [
            'sunat_payment_code.required' => 'Seleccione el código de medio de pago SUNAT.',
            'sunat_payment_code.in' => 'El código de medio de pago SUNAT no es válido.',
            'operation_date.required' => 'La fecha de la operación es obligatoria.',
            'operation_date.date_format' => 'La fecha de operación debe tener el formato YYYY-MM-DD.',
            'bancarization_exception.in' => 'La excepción de bancarización seleccionada no es válida.',
        ];
    }
}
