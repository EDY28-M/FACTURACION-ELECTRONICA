<?php

namespace Modules\Treasury\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Treasury\Models\TreasuryInstrument;

/**
 * Create and update share the same rules. Business rules (deadlines, deferred limit, account/establishment scope)
 * are enforced by InstrumentService.
 */
class StoreInstrumentRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            // The type is immutable: required on create, ignored on update.
            'type' => ['nullable', 'in:' . implode(',', TreasuryInstrument::TYPES)],
            'number' => ['required', 'string', 'max:40'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:9999999999.99'],
            'currency_type_id' => ['required', 'string', 'size:3'],
            'issue_date' => ['required', 'date_format:Y-m-d'],
            'due_date' => ['nullable', 'date_format:Y-m-d'],
            'is_deferred' => ['nullable', 'boolean'],
            'bank_id' => ['nullable', 'integer', 'min:1'],
            'bank_account_id' => ['nullable', 'integer', 'min:1'],
            'person_id' => ['nullable', 'integer', 'min:1'],
            'drawer_name' => ['nullable', 'string', 'max:255'],
            'payee_name' => ['nullable', 'string', 'max:255'],
            'establishment_id' => ['nullable', 'integer', 'min:1'],
            'source_id' => ['nullable', 'integer', 'min:1'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages()
    {
        return [
            'type.required_without' => 'Seleccione el tipo de documento.',
            'type.in' => 'El tipo de documento no es válido.',
            'number.required' => 'El número del documento es obligatorio.',
            'number.max' => 'El número no puede superar los 40 caracteres.',
            'amount.required' => 'El monto es obligatorio.',
            'amount.numeric' => 'El monto debe ser un valor numérico válido.',
            'amount.min' => 'El monto debe ser mayor a cero.',
            'amount.max' => 'El monto supera el máximo permitido.',
            'currency_type_id.required' => 'La moneda es obligatoria.',
            'currency_type_id.size' => 'La moneda no es válida.',
            'issue_date.required' => 'La fecha de emisión es obligatoria.',
            'issue_date.date_format' => 'La fecha de emisión debe tener el formato AAAA-MM-DD.',
            'due_date.date_format' => 'La fecha de vencimiento debe tener el formato AAAA-MM-DD.',
            'notes.max' => 'Las observaciones no pueden superar los 500 caracteres.',
        ];
    }
}
