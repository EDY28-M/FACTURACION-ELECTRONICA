<?php

namespace Modules\Treasury\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PleExportRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'period' => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'establishment_id' => ['nullable', 'integer', 'min:1'],
            'format' => ['nullable', 'string', Rule::in(['1.1', '1.2'])],
            'currency' => ['nullable', 'string', Rule::in(['PEN', 'USD'])],
        ];
    }

    public function messages()
    {
        return [
            'period.required' => 'Seleccione el periodo (mes) del PLE.',
            'period.regex' => 'El periodo debe tener el formato AAAA-MM.',
            'format.in' => 'El formato debe ser 1.1 o 1.2.',
            'currency.in' => 'La moneda debe ser PEN o USD.',
        ];
    }
}
