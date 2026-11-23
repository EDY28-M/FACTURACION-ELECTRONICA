<?php

namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ConfigurationEcommerceRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'information_contact_email' => ['required', 'string', 'email', 'max:255'],
            'information_contact_name' => ['required', 'string', 'max:255'],
            'information_contact_phone' => ['required', 'string', 'max:50', 'regex:/^[0-9+\-\s().]{6,50}$/'],
            'information_contact_address' => ['required', 'string', 'max:255'],
        ];
    }

    public function messages()
    {
        return [
            'information_contact_email.required' => 'El correo electrónico es obligatorio.',
            'information_contact_email.email' => 'El correo electrónico no es válido.',
            'information_contact_email.max' => 'El correo electrónico no debe superar 255 caracteres.',
            'information_contact_name.required' => 'El nombre de contacto es obligatorio.',
            'information_contact_name.max' => 'El nombre de contacto no debe superar 255 caracteres.',
            'information_contact_phone.required' => 'El campo Teléfono es obligatorio.',
            'information_contact_phone.regex' => 'El teléfono solo puede tener números, espacios y los signos + - ( ), con al menos 6 caracteres.',
            'information_contact_phone.max' => 'El campo Teléfono no debe superar 50 caracteres.',
            'information_contact_address.required' => 'El campo Dirección es obligatorio.',
            'information_contact_address.max' => 'El campo Dirección no debe superar 255 caracteres.',
            '*.string' => 'El valor ingresado no es válido.',
        ];
    }
}
