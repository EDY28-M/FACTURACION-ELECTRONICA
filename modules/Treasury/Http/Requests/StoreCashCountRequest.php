<?php

namespace Modules\Treasury\Http\Requests;

use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Modules\Treasury\Support\Money;

class StoreCashCountRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'cash_id' => ['required', 'integer', 'min:1'],
            'counted_at' => ['nullable', 'date'],
            'denominations' => ['required', 'array', 'min:1', 'max:50'],
            'denominations.*.value' => ['required', 'numeric', 'gt:0', 'max:100000'],
            'denominations.*.qty' => ['required', 'integer', 'min:0', 'max:1000000'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            if ($v->errors()->isNotEmpty()) {
                return;
            }

            $countedAt = $this->input('counted_at');
            if ($countedAt) {
                try {
                    if (Carbon::parse($countedAt)->gt(Carbon::now()->addMinutes(5))) {
                        $v->errors()->add('counted_at', 'La fecha y hora del arqueo no puede ser futura.');
                    }
                } catch (\Throwable $e) {
                    $v->errors()->add('counted_at', 'La fecha y hora del arqueo no es válida.');
                }
            }

            $seen = [];
            foreach ((array) $this->input('denominations', []) as $item) {
                if (!is_array($item) || !isset($item['value']) || !is_numeric($item['value'])) {
                    continue;
                }
                $key = Money::format((float) $item['value']);
                if (isset($seen[$key])) {
                    $v->errors()->add('denominations', "La denominación S/ {$key} está duplicada. Consolide las cantidades en una sola fila.");
                    break;
                }
                $seen[$key] = true;
            }
        });
    }

    public function messages()
    {
        return [
            'cash_id.required' => 'Seleccione la caja a arquear.',
            'cash_id.integer' => 'La caja seleccionada no es válida.',
            'denominations.required' => 'Ingrese el detalle de billetes y monedas contados.',
            'denominations.min' => 'Debe registrar al menos una denominación.',
            'denominations.max' => 'No se pueden registrar más de 50 denominaciones en un mismo arqueo.',
            'denominations.*.value.required' => 'Cada denominación debe indicar su valor.',
            'denominations.*.value.gt' => 'El valor de cada denominación debe ser mayor a cero.',
            'denominations.*.value.max' => 'El valor de cada denominación no puede superar 100,000.',
            'denominations.*.qty.required' => 'Indique la cantidad para cada denominación.',
            'denominations.*.qty.integer' => 'La cantidad debe ser un número entero.',
            'denominations.*.qty.min' => 'La cantidad no puede ser negativa.',
            'denominations.*.qty.max' => 'La cantidad no puede superar 1,000,000.',
            'notes.max' => 'Las observaciones no pueden superar los 1000 caracteres.',
        ];
    }
}
