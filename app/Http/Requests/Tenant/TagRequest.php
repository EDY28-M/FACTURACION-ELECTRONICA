<?php

namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TagRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            // The description is an internal note; the storefront menu uses the name only.
            'description' => ['nullable', 'string', 'max:255'],
            'name' => [
                'required', 'string', 'max:255',
                // Categories already removed (status = 0) do not block reusing their name.
                Rule::unique('tenant.tags', 'name')->where('status', 1)->ignore($this->input('id')),
            ],
        ];
    }

    public function messages()
    {
        return [
            'name.required' => 'El campo nombre es obligatorio.',
            'name.unique' => 'Ya existe una categoría con ese nombre.',
            'description.max' => 'El campo descripción no debe superar 255 caracteres.',
            'name.max' => 'El campo nombre no debe superar 255 caracteres.',
            '*.string' => 'El valor ingresado no es válido.',
        ];
    }
}
