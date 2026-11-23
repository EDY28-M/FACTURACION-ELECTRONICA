<?php

namespace Modules\Treasury\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DepositDetractionRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'date' => ['required', 'date_format:Y-m-d'],
            'bank_account_id' => ['nullable', 'integer', 'min:1'],
            'constancia_number' => ['nullable', 'string', 'max:30'],
            'operation_number' => ['nullable', 'string', 'max:60'],
        ];
    }

    public function messages()
    {
        return [
            'date.required' => 'La fecha del depósito es obligatoria.',
            'date.date_format' => 'La fecha del depósito debe tener el formato AAAA-MM-DD.',
            'bank_account_id.integer' => 'Seleccione una cuenta bancaria válida.',
            'constancia_number.max' => 'El número de constancia no puede superar los 30 caracteres.',
            'operation_number.max' => 'El número de operación no puede superar los 60 caracteres.',
        ];
    }
}
