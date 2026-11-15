<?php

namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;
use App\CoreFacturalo\Helpers\Sunat\RucValidator;
use Illuminate\Validation\Rule;

class PersonRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            // Only RUC (catalog 06 code '6') gets the Modulo 11 check; DNI, CE, passport, etc. are untouched.
            $type = (string) $this->input('identity_document_type_id');
            if ($type !== RucValidator::IDENTITY_DOCUMENT_TYPE_ID || $validator->errors()->has('number')) {
                return;
            }

            // Editing other fields of an existing person must not fail because of a RUC stored
            // before this check existed; only a new or changed RUC is validated.
            $id = $this->input('id');
            $stored = $id ? \App\Models\Tenant\Person::query()->whereKey($id)->first(['identity_document_type_id', 'number']) : null;
            if ($stored
                && (string) $stored->identity_document_type_id === $type
                && trim((string) $stored->number) === trim((string) $this->input('number'))) {
                return;
            }

            if (!RucValidator::isValid($this->input('number'))) {
                $validator->errors()->add('number', RucValidator::message($this->input('number')));
            }
        });
    }

    public function rules()
    {
        $id = $this->input('id');
        $type = $this->input('type');
        return [
            'number' => [
                'required',
                Rule::unique('tenant.persons')->where(function ($query) use($id, $type) {
                    return $query->where('type', $type)
                                 ->where('id', '<>' ,$id);
                })
            ],
            'name' => [
                'required',
                Rule::unique('tenant.persons')->where(function ($query) use($id, $type) {
                    return $query->where('type', $type)
                                 ->where('id', '<>' ,$id);
                })
            ],
            'identity_document_type_id' => [
                'required',
            ],
            'country_id' => [
                'required',
            ],
            // 'person_type_id' => [
            //     'required_if:type,"customers"',
            // ],
            'department_id' => [
                'required_if:identity_document_type_id,"066"',
            ],
            'province_id' => [
                'required_if:identity_document_type_id,"066"',
            ],
            'district_id' => [
                'required_if:identity_document_type_id,"066"',
            ],
            'address' => [
                'required_if:identity_document_type_id,"066"',
            ],
            'email' => [
                'nullable',
                'email',
            ],
            'internal_code' => 'max:100'
        ];
    }
}
