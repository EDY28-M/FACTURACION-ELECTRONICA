<?php

namespace Modules\Treasury\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Saves (creates or updates) a named column mapping for statement imports.
 */
class SaveStatementMappingRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'id' => ['nullable', 'integer', 'min:1'],
            'name' => ['required', 'string', 'max:100'],
            'bank_account_id' => ['nullable', 'integer', 'min:1'],
            'config' => ['required', 'array'],
        ];
    }

    public function messages()
    {
        return [
            'name.required' => 'El nombre del mapeo es obligatorio.',
            'name.max' => 'El nombre del mapeo no puede superar los 100 caracteres.',
            'config.required' => 'Indique la configuración de columnas.',
            'config.array' => 'La configuración de columnas no es válida.',
        ];
    }
}
