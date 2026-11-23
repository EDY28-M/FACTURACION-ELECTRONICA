<?php

namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ItemRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        $id = $this->input('id');
        return [
            'internal_id' => [
                // A product published on the storefront needs an internal code (same rule as the visibility toggle).
                Rule::requiredIf(function () {
                    return filter_var($this->input('apply_store'), FILTER_VALIDATE_BOOLEAN);
                }),
                'nullable',
                Rule::unique('tenant.items')->ignore($id),
            ],
            'description' => [
                'required', 'max:600'
            ],
            'name' => [
                'max:600'
            ],
            'second_name' => [
                'max:600'
            ],
            // 'name' => [
            //     'required',
            // ],
            // 'second_name' => [
            //     'required',
            // ],
            'unit_type_id' => [
                'required',
            ],
            'currency_type_id' => [
                'required'
            ],
            'sale_unit_price' => [
                'required',
                'numeric',
                'gt:0'
            ],
            'purchase_unit_price' => [
                'required', 'numeric'
            ],
            'stock' => [
                'required',
                // 'gt:0'
            ],
            'stock_min' => [
                'required',
                // 'gt:0'
            ],
            'sale_affectation_igv_type_id' => [
                'required'
            ],
            'purchase_affectation_igv_type_id' => [
                'required'
            ],
            // 'category_id' => [
            //     'required_if:is_set,false',
            // ],
            // 'brand_id' => [
            //     'required_if:is_set,false',
            // ],
            'item_code' => [
                'nullable',
                \App\CoreFacturalo\Helpers\Sunat\ProductCode::itemCodeRule(\App\CoreFacturalo\Helpers\Sunat\ProductCode::storedItemCode($id)),
            ],
            'model' => 'max:100'

        ];
    }

    public function messages()
    {
        return [
            'description.required' => 'El campo nombre es obligatorio.',
            'internal_id.required' => 'Para mostrar el producto en la tienda virtual, asigne un código interno.',
            'name.max' => 'La descripción debe ser inferior a 600 caracteres.',
            'sale_unit_price.gt' => 'El precio unitario de venta debe ser mayor que 0.',
        ];
    }
}
