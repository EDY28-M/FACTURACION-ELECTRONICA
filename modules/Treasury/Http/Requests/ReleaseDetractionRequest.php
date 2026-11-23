<?php

namespace Modules\Treasury\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReleaseDetractionRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'date' => ['required', 'date_format:Y-m-d'],
            'origin_id' => ['required', 'integer', 'min:1'],
            'target_id' => ['required', 'integer', 'min:1'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:9999999999.99'],
            'operation_number' => ['nullable', 'string', 'max:60'],
            'description' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages()
    {
        return [
            'date.required' => 'La fecha de la liberación es obligatoria.',
            'date.date_format' => 'La fecha de la liberación debe tener el formato AAAA-MM-DD.',
            'origin_id.required' => 'Seleccione la cuenta de detracciones de origen.',
            'target_id.required' => 'Seleccione la cuenta de destino de los fondos liberados.',
            'amount.required' => 'El monto es obligatorio.',
            'amount.numeric' => 'El monto debe ser un valor numérico.',
            'amount.min' => 'El monto debe ser mayor a 0.',
            'amount.max' => 'El monto excede el máximo permitido.',
        ];
    }
}
