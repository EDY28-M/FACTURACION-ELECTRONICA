<?php

namespace Modules\Treasury\Http\Requests;

/**
 * Import of a bank statement: the same file/mapping rules as the preview plus account, period and balances.
 */
class ImportStatementRequest extends PreviewStatementRequest
{
    public function rules()
    {
        return array_merge(parent::rules(), [
            'bank_account_id' => ['required', 'integer', 'min:1'],
            'period_from' => ['required', 'date_format:Y-m-d'],
            'period_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_from'],
            'opening_balance' => ['required', 'numeric', 'between:-9999999999.99,9999999999.99'],
            'closing_balance' => ['required', 'numeric', 'between:-9999999999.99,9999999999.99'],
            'accept_unbalanced' => ['nullable', 'boolean'],
        ]);
    }

    public function messages()
    {
        return array_merge(parent::messages(), [
            'bank_account_id.required' => 'Seleccione la cuenta bancaria del extracto.',
            'bank_account_id.integer' => 'Seleccione una cuenta bancaria válida.',
            'period_from.required' => 'El inicio del período es obligatorio.',
            'period_from.date_format' => 'El inicio del período debe tener el formato AAAA-MM-DD.',
            'period_to.required' => 'El fin del período es obligatorio.',
            'period_to.date_format' => 'El fin del período debe tener el formato AAAA-MM-DD.',
            'period_to.after_or_equal' => 'El fin del período no puede ser anterior al inicio.',
            'opening_balance.required' => 'El saldo inicial es obligatorio.',
            'opening_balance.numeric' => 'El saldo inicial debe ser un número válido.',
            'opening_balance.between' => 'El saldo inicial excede el máximo permitido.',
            'closing_balance.required' => 'El saldo final es obligatorio.',
            'closing_balance.numeric' => 'El saldo final debe ser un número válido.',
            'closing_balance.between' => 'El saldo final excede el máximo permitido.',
        ]);
    }
}
