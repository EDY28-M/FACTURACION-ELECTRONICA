<?php

namespace App\CoreFacturalo\Services\Helpers;

use App\Models\Tenant\Catalogs\Department;
use App\Models\Tenant\Catalogs\District;
use App\Models\Tenant\Catalogs\Province;
use Modules\Services\Data\ServiceData;

/**
 * Queries the token based provider (ServiceData) and maps its payload
 * to the shape expected by the tenant frontend.
 */
class TokenProviderLookup
{
    public static function ruc($number)
    {
        $res = ServiceData::service('ruc', $number);
        $d = self::extract($res);
        if ($d === null) {
            return self::failure($res, 'El número de RUC ingresado no existe.');
        }

        $name = $d['nombre_o_razon_social'] ?? '';
        $location = self::location($d);

        return [
            'success' => true,
            'data' => [
                'name' => $name,
                'trade_name' => $d['nombre_comercial'] ?? $name,
                'address' => $d['direccion_completa'] ?? ($d['direccion'] ?? ''),
                'phone' => '',
                'department' => $location['department'],
                'department_id' => $location['department_id'],
                'province' => $location['province'],
                'province_id' => $location['province_id'],
                'district' => $location['district'],
                'district_id' => $location['district_id'],
                // SUNAT taxpayer status, shown as alerts in the person form.
                'state' => $d['estado'] ?? null,
                'condition' => $d['condicion'] ?? null,
                'perception_agent' => self::flag($d['es_agente_de_percepcion'] ?? null),
                'retention_agent' => self::flag($d['es_agente_de_retencion'] ?? null),
            ],
        ];
    }

    /**
     * Provider booleans may come as bool, "SI"/"NO" or 1/0.
     */
    private static function flag($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtoupper(trim((string) $value)), ['SI', 'SÍ', '1', 'TRUE'], true);
    }

    public static function dni($number)
    {
        $res = ServiceData::service('dni', $number);
        $d = self::extract($res);
        if ($d === null) {
            return self::failure($res, 'Datos no encontrados.');
        }

        $first = $d['apellido_paterno'] ?? '';
        $last = $d['apellido_materno'] ?? '';
        $names = $d['nombres'] ?? '';
        $name = $d['nombre_completo'] ?? trim($first.' '.$last.' '.$names);

        $data = [
            'number' => $d['numero'] ?? (string) $number,
            'verification_code' => $d['codigo_verificacion'] ?? Functions::verificationCode($number),
            'name' => $name,
            'first_name' => $first,
            'last_name' => $last,
            'names' => $names,
            'address' => $d['direccion'] ?? '',
        ];

        if (!empty($d['departamento']) || !empty(array_filter((array) ($d['ubigeo'] ?? [])))) {
            $location = self::location($d);
            $data['department_id'] = $location['department_id'];
            $data['province_id'] = $location['province_id'];
            $data['district_id'] = $location['district_id'];
        }

        return ['success' => true, 'data' => $data];
    }

    private static function extract($res)
    {
        if (!is_array($res) || empty($res['success']) || empty($res['data']) || !is_array($res['data'])) {
            return null;
        }

        return $res['data'];
    }

    private static function failure($res, $notFoundMessage)
    {
        $message = (is_array($res) && !empty($res['message']) && is_string($res['message']))
            ? $res['message']
            : $notFoundMessage;

        // Provider-level failures (quota, auth, network) are passed through unchanged.
        return ['success' => false, 'message' => $message];
    }

    private static function location(array $d)
    {
        $department = $d['departamento'] ?? '';
        $province = $d['provincia'] ?? '';
        $district = $d['distrito'] ?? '';

        $ubigeo = $d['ubigeo'] ?? null;
        if (is_array($ubigeo) && count($ubigeo) === 3 && count(array_filter($ubigeo)) === 3) {
            [$dep_id, $prov_id, $dist_id] = array_map('strval', array_values($ubigeo));
            if (Department::where('id', $dep_id)->exists()
                && Province::where('id', $prov_id)->exists()
                && District::where('id', $dist_id)->exists()) {
                return [
                    'department' => $department ?: 'LIMA',
                    'department_id' => $dep_id,
                    'province' => $province ?: 'LIMA',
                    'province_id' => $prov_id,
                    'district' => $district ?: 'LIMA',
                    'district_id' => $dist_id,
                ];
            }
        }

        $province_id = Province::idByDescription($province);

        return [
            'department' => $department ?: 'LIMA',
            'department_id' => Department::idByDescription($department),
            'province' => $province ?: 'LIMA',
            'province_id' => $province_id,
            'district' => $district ?: 'LIMA',
            'district_id' => District::idByDescription($district, $province_id),
        ];
    }
}
