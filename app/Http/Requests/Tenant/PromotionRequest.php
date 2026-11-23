<?php

namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PromotionRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:255'],
            // A promotion links to a published product: its "Comprar ahora" button leads to the product page.
            'item_id' => ['required', 'integer', Rule::exists('tenant.items', 'id')->where('apply_store', 1)->where('active', 1)],
            'image' => ['nullable', 'string', 'max:255'],
            'temp_path' => ['nullable', 'string', 'max:1024'],
        ];
    }

    public function messages()
    {
        return [
            'item_id.required' => 'El campo Producto es obligatorio.',
            'item_id.integer' => 'El campo Producto no es válido.',
            'item_id.exists' => 'El producto seleccionado no existe o no está publicado en la tienda.',
            'name.max' => 'El campo nombre no debe superar 255 caracteres.',
            'description.max' => 'El campo descripción no debe superar 255 caracteres.',
            'image.max' => 'El nombre de la imagen no debe superar 255 caracteres.',
            '*.string' => 'El valor ingresado no es válido.',
        ];
    }
}
