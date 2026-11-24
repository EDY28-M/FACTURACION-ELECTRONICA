<?php

namespace App\Http\Requests\Tenant;

use App\Models\Tenant\BankAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BankAccountRequest extends FormRequest
{
	public function authorize()
	{
		return true;
	}

	public function rules()
	{
		$id = $this->input('id');

		return [
			'bank_id' => [
				'required',
			],
			'description' => [
				'required',
			],
			'number' => [
				'required',
			],
			'currency_type_id' => [
				'required',
			],
			'cci' => [
				'nullable',
				'string',
				'max:255',
			],
			'initial_balance' => [
				'required',
				'numeric',
				'min:0'
			],
			'show_in_documents' => [
				'required',
				'boolean'
			],
			'account_type' => [
				'nullable',
				'string',
				Rule::in(BankAccount::ACCOUNT_TYPES),
			],
			'pcge_account' => [
				'nullable',
				'string',
				'max:20',
			],
			'sunat_financial_entity_code' => [
				'nullable',
				'string',
				'regex:/^\d{2}$/',
			],
			'itf_exempt' => [
				'nullable',
				'boolean',
			],
			'establishment_id' => [
				'nullable',
				'integer',
				'exists:tenant.establishments,id',
			],
		];
	}

	public function messages()
	{
		return [
			// 'initial_balance.gte' => 'El saldo inicial debe ser mayor o igual que 0.',
			'sunat_financial_entity_code.regex' => 'El código de entidad financiera (Tabla 3 PLE) debe tener 2 dígitos.',
		];
	}
}
