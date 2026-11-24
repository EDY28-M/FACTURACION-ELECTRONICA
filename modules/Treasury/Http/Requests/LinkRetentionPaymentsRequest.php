<?php

namespace Modules\Treasury\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Treasury\Services\RetentionPerceptionService;

/**
 * Links one issued CRE to several supplier payments.
 */
class LinkRetentionPaymentsRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'series' => ['required', 'string', 'max:10'],
            'number' => ['required', 'string', 'max:20'],
            'payment_ids' => ['required', 'array', 'min:1', 'max:' . RetentionPerceptionService::MAX_LINKED_PAYMENTS],
            'payment_ids.*' => ['integer', 'min:1', 'distinct'],
        ];
    }

    public function messages()
    {
        return [
            'series.required' => 'La serie del comprobante es obligatoria.',
            'series.max' => 'La serie no puede superar los 10 caracteres.',
            'number.required' => 'El número del comprobante es obligatorio.',
            'number.max' => 'El número no puede superar los 20 caracteres.',
            'payment_ids.required' => 'Seleccione al menos un pago.',
            'payment_ids.array' => 'Seleccione al menos un pago.',
            'payment_ids.min' => 'Seleccione al menos un pago.',
            'payment_ids.max' => 'Un comprobante no puede enlazarse a más de ' . RetentionPerceptionService::MAX_LINKED_PAYMENTS . ' pagos.',
            'payment_ids.*.integer' => 'Los pagos seleccionados no son válidos.',
            'payment_ids.*.min' => 'Los pagos seleccionados no son válidos.',
            'payment_ids.*.distinct' => 'No repita pagos en la selección.',
        ];
    }
}
