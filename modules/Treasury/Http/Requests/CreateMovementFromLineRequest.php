<?php

namespace Modules\Treasury\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Treasury\Services\ReconciliationService;

/**
 * Creates a Treasury movement from an unmatched statement line (and matches it).
 */
class CreateMovementFromLineRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'kind' => ['required', 'in:' . implode(',', array_keys(ReconciliationService::LINE_MOVEMENT_KINDS))],
            'treasury_concept_id' => ['nullable', 'integer', 'min:1'],
            'description' => ['nullable', 'string', 'max:500'],
            'establishment_id' => ['nullable', 'integer', 'min:1'],
            'counterparty_name' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages()
    {
        return [
            'kind.required' => 'Seleccione el tipo de movimiento.',
            'kind.in' => 'El tipo de movimiento no es válido para una línea del extracto.',
            'treasury_concept_id.integer' => 'Seleccione un concepto válido.',
            'description.max' => 'La descripción no puede superar los 500 caracteres.',
            'counterparty_name.max' => 'El nombre de la contraparte no puede superar los 255 caracteres.',
        ];
    }
}
