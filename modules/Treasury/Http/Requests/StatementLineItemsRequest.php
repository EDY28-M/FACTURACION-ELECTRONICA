<?php

namespace Modules\Treasury\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Manual match of a statement line with one or several system items.
 */
class StatementLineItemsRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.type' => ['required', 'in:movement,global_payment'],
            'items.*.id' => ['required', 'integer', 'min:1'],
        ];
    }

    public function messages()
    {
        return [
            'items.required' => 'Seleccione al menos un movimiento del sistema.',
            'items.array' => 'La selección de movimientos no es válida.',
            'items.min' => 'Seleccione al menos un movimiento del sistema.',
            'items.max' => 'No puede emparejar más de 50 movimientos con una sola línea.',
            'items.*.type.required' => 'Uno de los movimientos seleccionados no es válido.',
            'items.*.type.in' => 'Uno de los movimientos seleccionados no es válido.',
            'items.*.id.required' => 'Uno de los movimientos seleccionados no es válido.',
            'items.*.id.integer' => 'Uno de los movimientos seleccionados no es válido.',
        ];
    }
}
