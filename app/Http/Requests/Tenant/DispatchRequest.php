<?php

namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DispatchRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        //$id = $this->input('id');

        return [
            'unit_type_id' => [
                'required',
            ],
            // 'transfer_reason_description' => [
            //     'required',
            // ],
            // 'observations' => [
            //     'required',
            // ],
            'delivery.address'=> [
                'required',
                'max:100',
               
            ],
            'dispatcher.identity_document_type_id'=> [
                'required_if:transport_mode_type_id,01',
            ],
            'dispatcher.number'=> [
                'required_if:transport_mode_type_id,01',
            ],
            'dispatcher.name'=> [
                'required_if:transport_mode_type_id,01',
            ],
            'driver.identity_document_type_id'=> [
                'required_if:transport_mode_type_id,02',
            ],
            'driver.number'=> [
                'required_if:transport_mode_type_id,02',
            ],
            // GRE 2.0 (series T, private transport) needs the driver full name and license.
            'driver.first_name'=> [
                Rule::requiredIf($this->isElectronicPrivateTransport()),
            ],
            'driver.family_name'=> [
                Rule::requiredIf($this->isElectronicPrivateTransport()),
            ],
            'driver.license'=> [
                Rule::requiredIf($this->isElectronicPrivateTransport()),
            ],
            'license_plate'=> [
                'required_if:transport_mode_type_id,02',
            ],

            'customer_id'=> [
                'required',
            ],
            'transport_mode_type_id'=> [
                'required',
            ],
            'transfer_reason_type_id'=> [
                'required',
            ],
            'origin.address'=> [
                'required',
                'max:100',
            ],

            
           
        ];
    }

    private function isElectronicPrivateTransport()
    {
        return $this->input('transport_mode_type_id') === '02'
            && substr((string) $this->input('series'), 0, 1) === 'T';
    }

    public function messages()
    {
        return [
        'transfer_reason_description.required' => 'El campo Descripción de motivo de traslado es obligatorio.',
        'observations.required' => 'El campo Observaciones es obligatorio.',
        'dispatcher.identity_document_type_id.required' => 'El campo Tipo Doc. Identidad es obligatorio.',
        'dispatcher.number.required' => 'El campo Número es obligatorio.',
        'dispatcher.name.required' => 'El campo Nombre y/o razón social es obligatorio.',
        'driver.identity_document_type_id.required' => 'El campo Tipo Doc. Identidad es obligatorio.',
        'driver.number.required' => 'El campo Número es obligatorio.',
        'license_plate.required_if' => 'El campo Número de placa del vehiculo es obligatorio.',
        'dispatcher.identity_document_type_id.required_if' => 'El campo Tipo Doc. Identidad es obligatorio.',
        'dispatcher.number.required_if' => 'El campo Número es obligatorio.',
        'dispatcher.name.required_if' => 'El campo Nombre y/o razón social es obligatorio.',
        'driver.identity_document_type_id.required_if' => 'El campo Tipo Doc. Identidad es obligatorio.',
        'driver.number.required_if' => 'El campo Número es obligatorio.',
        'driver.first_name.required' => 'Los nombres del conductor son obligatorios para guías electrónicas.',
        'driver.family_name.required' => 'Los apellidos del conductor son obligatorios para guías electrónicas.',
        'driver.license.required' => 'La licencia del conductor es obligatoria para guías electrónicas.',
        ];
    }
}