<?php

namespace Modules\Treasury\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VoidMovementRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'void_reason' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }

    public function messages()
    {
        return [
            'void_reason.required' => 'El motivo de anulación es obligatorio.',
            'void_reason.min' => 'El motivo de anulación debe tener al menos 3 caracteres.',
            'void_reason.max' => 'El motivo de anulación no puede superar los 500 caracteres.',
        ];
    }
}
