<?php

namespace App\CoreFacturalo\Helpers\Sunat;

use Carbon\Carbon;

/**
 * SUNAT operation type 0202: export of services, lodging for non-domiciled guests
 * (Ley del IGV art. 33 / Apendice V: stay of up to 60 days per entry).
 *
 * Every lodging line (affectation 40) carries the Catalogo 55 properties 4000-4005:
 * 4000 passport issuing country, 4001 residence country, 4002 entry date to the country,
 * 4003 check-in date, 4004 check-out date, 4005 days of stay (SUNAT error 3145 when missing).
 *
 * Keep in sync with the hotel checkout (modules/Hotel/.../Checkout.vue).
 */
class HospitalityExport
{
    public const OPERATION_TYPE = '0202';

    public const MAX_STAY_DAYS = 60;

    public const EXPORT_AFFECTATION = '40';

    /** Catalogo 06 types accepted for the non-domiciled guest: passport only. */
    public const ALLOWED_IDENTITY_TYPES = ['7'];

    /**
     * Builds the Catalogo 55 attributes of a lodging line.
     */
    public static function lodgingAttributes($passportCountry, $residenceCountry, $entryDate, $checkIn, $checkOut, $days): array
    {
        return [
            ['attribute_type_id' => '4000', 'description' => 'Beneficio Hospedajes-Paquete turístico: Código de país de emisión del pasaporte', 'value' => $passportCountry, 'start_date' => null, 'end_date' => null, 'duration' => null],
            ['attribute_type_id' => '4001', 'description' => 'Beneficio Hospedajes: Código de país de residencia del sujeto no domiciliado', 'value' => $residenceCountry, 'start_date' => null, 'end_date' => null, 'duration' => null],
            ['attribute_type_id' => '4002', 'description' => 'Beneficio Hospedajes: Fecha de ingreso al país', 'value' => null, 'start_date' => $entryDate, 'end_date' => null, 'duration' => null],
            ['attribute_type_id' => '4003', 'description' => 'Beneficio Hospedajes: Fecha de Ingreso al Establecimiento', 'value' => null, 'start_date' => $checkIn, 'end_date' => null, 'duration' => null],
            ['attribute_type_id' => '4004', 'description' => 'Beneficio Hospedajes: Fecha de Salida del Establecimiento', 'value' => null, 'start_date' => null, 'end_date' => $checkOut, 'duration' => null],
            ['attribute_type_id' => '4005', 'description' => 'Beneficio Hospedajes: Número de Días de Permanencia', 'value' => null, 'start_date' => null, 'end_date' => null, 'duration' => $days],
        ];
    }

    /**
     * Throws when a document with operation type 0202 breaks the SUNAT rules.
     */
    public static function assert(array $inputs, ?callable $countryExists = null): void
    {
        // Facturalo::save() receives the normalized document, where the operation type lives in 'invoice'.
        $operationType = $inputs['invoice']['operation_type_id'] ?? $inputs['operation_type_id'] ?? null;

        if ($operationType !== self::OPERATION_TYPE) {
            return;
        }

        if (!in_array($inputs['document_type_id'] ?? null, ['01', '03'], true)) {
            throw new \Exception('La operación 0202 solo se puede emitir en factura o boleta.');
        }

        $identityType = (string) (($inputs['customer'] ?? [])['identity_document_type_id'] ?? '');
        if (!in_array($identityType, self::ALLOWED_IDENTITY_TYPES, true)) {
            throw new \Exception('La operación 0202 (hospedaje a no domiciliados) requiere un huésped identificado con pasaporte.');
        }

        $lodging = 0;
        foreach ($inputs['items'] ?? [] as $item) {
            if ((string) ($item['affectation_igv_type_id'] ?? '') !== self::EXPORT_AFFECTATION) {
                continue;
            }
            $lodging++;

            if ((float) ($item['total_igv'] ?? 0) > 0) {
                throw new \Exception('Los servicios de hospedaje de la operación 0202 no deben incluir IGV.');
            }

            self::assertLodgingItem($item['attributes'] ?? [], $countryExists);
        }

        if ($lodging === 0) {
            throw new \Exception('La operación 0202 requiere al menos un ítem de hospedaje con afectación de exportación (40).');
        }
    }

    private static function assertLodgingItem($attributes, ?callable $countryExists = null): void
    {
        $byCode = [];
        foreach ((array) $attributes as $attribute) {
            $byCode[(string) ($attribute['attribute_type_id'] ?? '')] = $attribute;
        }

        foreach (['4000', '4001', '4002', '4003', '4004', '4005'] as $code) {
            if (!isset($byCode[$code])) {
                $suffix = $code === '4005' ? ' (error SUNAT 3145)' : '';
                throw new \Exception("El ítem de hospedaje debe informar la propiedad {$code} del Catálogo 55{$suffix}.");
            }
        }

        foreach (['4000', '4001'] as $code) {
            $country = trim((string) ($byCode[$code]['value'] ?? ''));
            if ($country === '') {
                throw new \Exception("La propiedad {$code} (código de país) es obligatoria para hospedaje a no domiciliados.");
            }
            if ($countryExists !== null && !$countryExists($country)) {
                throw new \Exception("La propiedad {$code} tiene un código de país inválido: {$country}.");
            }
        }

        $entry = self::parseDate($byCode['4002']['start_date'] ?? null, '4002');
        $checkIn = self::parseDate($byCode['4003']['start_date'] ?? null, '4003');
        $checkOut = self::parseDate($byCode['4004']['end_date'] ?? null, '4004');
        $days = $byCode['4005']['duration'] ?? null;

        if ($days === null || $days === '' || !is_numeric($days) || (int) $days != $days || (int) $days < 1) {
            throw new \Exception('El número de días de permanencia es obligatorio (propiedad 4005, error SUNAT 3145).');
        }
        $days = (int) $days;

        if ($entry->gt($checkIn)) {
            throw new \Exception('La fecha de ingreso al país (4002) no puede ser posterior al ingreso al establecimiento (4003).');
        }

        if (!$checkOut->gt($checkIn)) {
            throw new \Exception('La fecha de salida (4004) debe ser posterior a la fecha de ingreso al establecimiento (4003).');
        }

        $nights = $checkIn->diffInDays($checkOut);

        if ($days !== $nights) {
            throw new \Exception("Los días de permanencia (4005: {$days}) no coinciden con las noches entre el ingreso y la salida ({$nights}).");
        }

        if ($nights > self::MAX_STAY_DAYS) {
            throw new \Exception('El hospedaje a no domiciliados no puede exceder ' . self::MAX_STAY_DAYS . ' días de permanencia.');
        }
    }

    /**
     * Strict Y-m-d parsing: rejects relative strings ("tomorrow") and other formats.
     */
    private static function parseDate($value, string $code): Carbon
    {
        $value = (string) $value;
        try {
            $date = Carbon::createFromFormat('!Y-m-d', $value);
        } catch (\Throwable $e) {
            $date = false;
        }

        if ($value === '' || $date === false || $date->format('Y-m-d') !== $value) {
            throw new \Exception("La fecha de la propiedad {$code} debe tener el formato AAAA-MM-DD.");
        }

        return $date;
    }
}
