<?php

namespace Modules\Treasury\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Treasury\Models\TreasuryInstrument;

class TransitionInstrumentRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'to' => ['required', 'in:' . implode(',', TreasuryInstrument::STATUSES)],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'bank_account_id' => ['nullable', 'integer', 'min:1'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages()
    {
        return [
            'to.required' => 'Indique el nuevo estado.',
            'to.in' => 'El estado indicado no es válido.',
            'date.date_format' => 'La fecha debe tener el formato AAAA-MM-DD.',
            'bank_account_id.integer' => 'Seleccione una cuenta bancaria válida.',
            'notes.max' => 'El texto no puede superar los 500 caracteres.',
        ];
    }
}
