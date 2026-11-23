<?php

namespace Modules\Treasury\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePurchaseFeesRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'fees' => ['required', 'array', 'min:1', 'max:120'],
            'fees.*.date' => ['required', 'date_format:Y-m-d'],
            'fees.*.amount' => ['required', 'numeric', 'min:0.01', 'max:9999999999.99'],
        ];
    }

    public function messages()
    {
        return [
            'fees.required' => 'Debe registrar al menos una cuota para la compra.',
            'fees.array' => 'El formato de las cuotas no es válido.',
            'fees.min' => 'Debe registrar al menos una cuota para la compra.',
            'fees.max' => 'No se pueden registrar más de 120 cuotas por compra.',
            'fees.*.date.required' => 'La fecha de vencimiento de cada cuota es obligatoria.',
            'fees.*.date.date_format' => 'La fecha de vencimiento debe tener el formato YYYY-MM-DD.',
            'fees.*.amount.required' => 'El importe de cada cuota es obligatorio.',
            'fees.*.amount.numeric' => 'El importe de cada cuota debe ser un número válido.',
            'fees.*.amount.min' => 'El importe mínimo de una cuota es 0.01.',
            'fees.*.amount.max' => 'El importe de la cuota excede el límite permitido.',
        ];
    }
}
