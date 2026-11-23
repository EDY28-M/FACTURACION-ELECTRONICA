<?php

namespace Modules\Treasury\Http\Requests;

use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Treasury\Services\CashFlowService;

class CashFlowReportRequest extends FormRequest
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
            'mode' => ['nullable', 'string', Rule::in(['real', 'projected'])],
            'date_start' => ['nullable', 'date_format:Y-m-d'],
            'date_end' => $dateEndRules,
            'granularity' => ['nullable', 'string', Rule::in(['week', 'month'])],
            'establishment_id' => ['nullable', 'integer', 'min:1'],
            'currency_type_id' => ['nullable', 'string', Rule::in(['ALL', 'PEN', 'USD'])],
            'destination_type' => ['nullable', 'string', Rule::in(['all', 'cash', 'bank_account'])],
            'include_overdue' => ['nullable', 'boolean'],
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

            $mode = (string) ($this->input('mode') ?: 'real');
            $defaultGranularity = $mode === 'projected' ? 'week' : 'month';
            $granularity = (string) ($this->input('granularity') ?: $defaultGranularity);

            $days = Carbon::parse($start)->diffInDays(Carbon::parse($end), false);
            if ($days < 0) {
                $v->errors()->add('date_end', 'La fecha final debe ser igual o posterior a la fecha inicial.');

                return;
            }

            if ($granularity === 'week' && $days > CashFlowService::MAX_WEEKLY_RANGE_DAYS) {
                $v->errors()->add('date_end', 'Para granularidad semanal el rango máximo permitido es de 731 días (2 años).');
            } elseif ($granularity === 'month' && $days > CashFlowService::MAX_MONTHLY_RANGE_DAYS) {
                $v->errors()->add('date_end', 'Para granularidad mensual el rango máximo permitido es de 1826 días (5 años).');
            }
        });
    }

    public function messages()
    {
        return [
            'mode.in' => 'El tipo de flujo debe ser real o proyectado.',
            'date_start.date_format' => 'La fecha inicial debe tener el formato YYYY-MM-DD.',
            'date_end.date_format' => 'La fecha final debe tener el formato YYYY-MM-DD.',
            'date_end.after_or_equal' => 'La fecha final debe ser igual o posterior a la fecha inicial.',
            'granularity.in' => 'La granularidad debe ser semanal o mensual.',
            'currency_type_id.in' => 'La moneda seleccionada no es válida.',
            'destination_type.in' => 'El destino seleccionado no es válido.',
        ];
    }
}
