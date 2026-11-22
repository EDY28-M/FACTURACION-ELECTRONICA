<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use Modules\Services\Data\ServiceData;
use App\CoreFacturalo\Services\Ruc\Sunat;
class ServiceController extends Controller
{
	public function ruc($number)
	{
		if (!ServiceData::isConfigured()) {
			$service = new Sunat();
			$res     = $service->get($number);
			if ($res) {
				return [
					'success' => true,
					'data'    => [
						'name'       => $res->razonSocial,
						'trade_name' => $res->nombreComercial,
					]
				];
			} else {
				return [
					'success' => false,
					'message' => $service->getError()
				];
			}
		} else {
			$data = ServiceData::service('ruc', $number);

			if (empty($data['success'])) {
				// Provider-level failure (quota, auth, network): show the real reason.
				return response()->json([
					'success' => false,
					'message' => !empty($data['message']) && is_string($data['message'])
						? $data['message']
						: 'El número de RUC ingresado no existe.'
				], 200);
			}

			if (empty($data['data']['nombre_o_razon_social'])) {
				return response()->json([
					'success' => false,
					'message' => 'El número de RUC ingresado no existe.'
				], 200);
			}

			return response()->json([
				'success' => true,
				'data'    => [
					'name'       => $data['data']['nombre_o_razon_social'],
					'trade_name' => $data['data']['nombre_o_razon_social'],
				]
			], 200);
		}
	}
}
