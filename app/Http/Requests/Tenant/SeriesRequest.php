<?php

namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SeriesRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        $id = $this->input('id');
        return [
            'document_type_id' => [
                'required',
            ],
            'number' => [
                'required',
            ],
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $type = $this->input('document_type_id');
            $number = strtoupper((string) $this->input('number'));

            // GRE series: V is reserved for the transportista guide (31); the remitente guide (09) cannot use it.
            if ($type === '31' && !preg_match('/^V[A-Z0-9]{3}$/', $number)) {
                $validator->errors()->add('number', 'La serie de la guía de remisión transportista debe iniciar con V (por ejemplo V001).');
            }
            if ($type === '09' && $number !== '' && $number[0] === 'V') {
                $validator->errors()->add('number', 'La serie V es exclusiva de la guía de remisión transportista. Para la guía remitente electrónica use una serie que inicie con T.');
            }
        });
    }
}