<?php

namespace Modules\Treasury\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Used for both "register received CRE" (needs date + amount) and "link issued CRE/CPE" (series + number only).
 */
class StoreWithholdingRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        $rules = [
            'series' => ['required', 'string', 'max:10'],
            'number' => ['required', 'string', 'max:20'],
            'rate_code' => ['nullable', 'string', 'max:20'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];

        if ($this->route('document')) {
            $rules['date'] = ['required', 'date_format:Y-m-d'];
            $rules['amount'] = ['required', 'numeric', 'min:0.01', 'max:9999999999.99'];
        }

        return $rules;
    }

    public function messages()
    {
        return [
            'series.required' => 'La serie del comprobante es obligatoria.',
            'series.max' => 'La serie no puede superar los 10 caracteres.',
            'number.required' => 'El número del comprobante es obligatorio.',
            'number.max' => 'El número no puede superar los 20 caracteres.',
            'date.required' => 'La fecha del comprobante es obligatoria.',
            'date.date_format' => 'La fecha debe tener el formato AAAA-MM-DD.',
            'amount.required' => 'El monto retenido es obligatorio.',
            'amount.numeric' => 'El monto debe ser un valor numérico.',
            'amount.min' => 'El monto debe ser mayor a 0.',
            'amount.max' => 'El monto excede el máximo permitido.',
        ];
    }
}
