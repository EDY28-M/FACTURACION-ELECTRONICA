<?php

namespace Modules\Hotel\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class HotelAddRateToRoomRequest extends FormRequest
{
	public function authorize()
	{
		return true;
	}

	public function rules()
	{
		return [
			'hotel_rate_id' => [
				'required', 'numeric', Rule::unique('tenant.hotel_room_rates', 'hotel_rate_id')->where('hotel_room_id', $this->route('id'))
			],
			'hotel_room_id' => ['nullable', 'numeric'],
			'price'         => 'required|numeric'
		];
	}
}
