<?php

namespace Modules\Treasury\Http\Requests;

use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Treasury\Services\CashBookReport;

class CashBookReportRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        $dateEndRules = ['nullable', 'date_format:Y-m-d'];
        if ($this->filled('date_start')) {
            $dateEndRules[] = 'after_or_equal:date_start';
        }

        return [
            'format' => ['required', 'string', Rule::in(['1.1', '1.2'])],
            'date_start' => ['nullable', 'date_format:Y-m-d'],
            'date_end' => $dateEndRules,
            'establishment_id' => ['nullable', 'integer', 'min:1'],
            'cash_id' => ['nullable', 'integer', 'min:1'],
            'bank_account_id' => ['required_if:format,1.2', 'nullable', 'integer', 'min:1'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            if ($v->errors()->isNotEmpty()) {
                return;
            }
            $start = $this->input('date_start');
            $end = $this->input('date_end');
            if (!$start || !$end) {
                return;
            }

            $maxDays = CashBookReport::maxRangeDays();
            if (Carbon::parse($start)->diffInDays(Carbon::parse($end), false) > $maxDays) {
                $v->errors()->add('date_end', 'El rango máximo permitido es de ' . $maxDays . ' días.');
            }
        });
    }

    public function messages()
    {
        return [
            'format.required' => 'Seleccione el formato del Libro Caja y Bancos (1.1 o 1.2).',
            'format.in' => 'El formato debe ser 1.1 (Efectivo) o 1.2 (Cuenta corriente).',
            'date_start.date_format' => 'La fecha inicial debe tener el formato YYYY-MM-DD.',
            'date_end.date_format' => 'La fecha final debe tener el formato YYYY-MM-DD.',
            'date_end.after_or_equal' => 'La fecha final debe ser igual o posterior a la fecha inicial.',
            'bank_account_id.required_if' => 'Seleccione una cuenta bancaria para generar el Formato 1.2.',
            'bank_account_id.integer' => 'La cuenta bancaria seleccionada no es válida.',
        ];
    }
}
