<?php

namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;
use App\Models\Tenant\Company;
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

        if ($this->isCarrierGuide()) {
            return $this->carrierRules();
        }

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

    /**
     * GRE Transportista (document type 31, series V###).
     */
    public function isCarrierGuide()
    {
        return $this->input('document_type_id') === '31';
    }

    /**
     * Rules from SUNAT annex 28 (RS 000123-2022): the carrier issues the guide, so vehicle,
     * driver and license are mandatory and there is no transfer reason / transport mode.
     */
    private function carrierRules()
    {
        $identity = [Rule::in(['1', '4', '6', '7', 'A'])];

        return [
            'series' => ['required', 'regex:/^V[A-Z0-9]{3}$/'],
            'customer_id' => ['required'],
            'unit_type_id' => ['required', Rule::in(['KGM', 'TNE'])],
            'total_weight' => ['required', 'numeric', 'gt:0'],
            'date_of_shipping' => ['required', 'date', 'after_or_equal:date_of_issue'],
            'origin.address' => ['required', 'max:100'],
            'delivery.address' => ['required', 'max:100'],
            'origin.location_id' => ['required'],
            'delivery.location_id' => ['required'],
            'items' => ['required', 'array', 'min:1'],
            'sender.identity_document_type_id' => array_merge(['required'], $identity),
            'sender.number' => ['required', 'max:15'],
            'sender.name' => ['required', 'max:250'],
            'driver.identity_document_type_id' => ['required'],
            'driver.number' => ['required', 'max:15'],
            'driver.first_name' => ['required', 'max:100'],
            'driver.family_name' => ['required', 'max:100'],
            'driver.license' => ['required', 'max:10'],
            'license_plate' => ['required', 'regex:/^[A-Za-z0-9\s\-]{6,10}$/'],
            'secondary_license_plates.semitrailer' => ['nullable', 'regex:/^[A-Za-z0-9\s\-]{6,10}$/'],
            'vehicle_details.tuc' => ['nullable', 'max:15'],
            'vehicle_details.authorization_number' => ['nullable', 'max:50'],
            'vehicle_details.semitrailer_tuc' => ['nullable', 'max:15'],
            'freight_payer' => ['required', Rule::in(['remitente', 'subcontratador', 'tercero'])],
            'freight_payer_party.identity_document_type_id' => ['required_if:freight_payer,tercero'],
            'freight_payer_party.number' => ['required_if:freight_payer,tercero', 'max:15'],
            'freight_payer_party.name' => ['required_if:freight_payer,tercero', 'max:250'],
            'subcontractor.number' => ['required_if:freight_payer,subcontratador', 'nullable', 'digits:11'],
            'subcontractor.name' => ['required_with:subcontractor.number', 'nullable', 'max:250'],
            'related_dispatches' => ['nullable', 'array', 'max:50'],
            'related_dispatches.*.series_number' => ['required', 'regex:/^[A-Za-z0-9]{4}-\d{1,8}$/'],
            'related_dispatches.*.ruc' => ['nullable', 'digits:11'],
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            if (!$this->isCarrierGuide()) {
                if ($this->input('document_type_id') === '09' && substr((string) $this->input('series'), 0, 1) === 'V') {
                    $validator->errors()->add('series', 'La serie V corresponde a la guía de remisión transportista.');
                }

                return;
            }

            $company = Company::active();
            if (!$company || !filled($company->mtc_registration_number)) {
                $validator->errors()->add(
                    'mtc_registration_number',
                    'Configure el número de registro MTC de la empresa antes de emitir una guía de remisión transportista.'
                );
            }
        });
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
        'series.regex' => 'La serie de la guía de remisión transportista debe iniciar con la letra V (por ejemplo V001).',
        'total_weight.gt' => 'El peso bruto total debe ser mayor a 0.',
        'items.required' => 'Debe agregar al menos un bien a transportar.',
        'items.min' => 'Debe agregar al menos un bien a transportar.',
        'sender.number.required' => 'El número de documento del remitente es obligatorio.',
        'sender.name.required' => 'El nombre o razón social del remitente es obligatorio.',
        'sender.identity_document_type_id.required' => 'El tipo de documento del remitente es obligatorio.',
        'license_plate.required' => 'El número de placa del vehículo es obligatorio.',
        'license_plate.regex' => 'La placa del vehículo debe tener entre 6 y 8 caracteres alfanuméricos.',
        'freight_payer.required' => 'Indique quién paga el flete.',
        'freight_payer_party.number.required_if' => 'Indique el documento de quien paga el flete.',
        'freight_payer_party.name.required_if' => 'Indique el nombre o razón social de quien paga el flete.',
        'subcontractor.number.required_if' => 'Indique el RUC del subcontratador.',
        'related_dispatches.*.series_number.regex' => 'La guía del remitente debe tener el formato SERIE-NÚMERO (por ejemplo T001-123).',
        ];
    }
}