<?php

namespace App\Services;

use App\Models\Tenant\Person;

/**
 * The single "CLIENTES VARIOS" customer used for anonymous sales (consumidor final).
 *
 * SUNAT allows a boleta without buyer identification up to S/ 700.00; the
 * threshold itself is enforced by BoletaIdentification (server) and
 * boleta-identification.js (client).
 */
class GenericCustomerService
{
    public const IDENTITY_DOCUMENT_TYPE_ID = '0';

    public const NUMBER = '99999999';

    public const NAME = 'CLIENTES VARIOS';

    /**
     * Returns the generic customer, creating it when the tenant has none.
     */
    public function getOrCreate(): Person
    {
        $person = Person::where('type', 'customers')
            ->where('identity_document_type_id', self::IDENTITY_DOCUMENT_TYPE_ID)
            ->where('number', self::NUMBER)
            ->orderBy('id')
            ->first();

        if ($person) {
            return $person;
        }

        return Person::create([
            'type' => 'customers',
            'identity_document_type_id' => self::IDENTITY_DOCUMENT_TYPE_ID,
            'number' => self::NUMBER,
            'name' => self::NAME,
            'country_id' => 'PE',
            'address' => '-',
            'perception_agent' => 0,
            'enabled' => 1,
        ]);
    }

    /**
     * Same shape used by the customer lists of POS and document forms.
     */
    public function toArray(Person $person): array
    {
        return [
            'id' => $person->id,
            'description' => $person->number.' - '.$person->name,
            'name' => $person->name,
            'number' => $person->number,
            'identity_document_type_id' => $person->identity_document_type_id,
            'identity_document_type_code' => $person->identity_document_type->code,
            'addresses' => $person->addresses,
            'address' => $person->address,
            'state' => $person->state,
            'condition' => $person->condition,
        ];
    }
}
