<?php

namespace App\Models\Tenant;

use App\Models\Tenant\Catalogs\IdentityDocumentType;

class Company extends ModelTenant
{
    protected $with = ['identity_document_type'];
    protected $fillable = [
        'user_id',
        'identity_document_type_id',
        'number',
        'name',
        'trade_name',
        'soap_send_id',
        'soap_type_id',
        'soap_username',
        'soap_password',
        'soap_url',
        'certificate',
        'certificate_due',
        'logo',
        'detraction_account',
        'operation_amazonia',
        'img_firm',
        'gre_client_id',
        'mtc_registration_number',
        'sire_client_id',
        'cpe_client_id',
        'is_retention_agent',
        'retention_agent_since',
        'is_perception_agent',
        'is_good_taxpayer',
    ];

    protected $casts = [
        'is_retention_agent' => 'boolean',
        'retention_agent_since' => 'date',
        'is_perception_agent' => 'boolean',
        'is_good_taxpayer' => 'boolean',
    ];

    protected $hidden = ['gre_client_secret', 'sire_client_secret', 'cpe_client_secret'];

    /**
     * Stores the GRE API client secret encrypted. Null/empty clears it.
     */
    public function setGreClientSecret($plain)
    {
        $this->attributes['gre_client_secret'] = ($plain === null || $plain === '') ? null : encrypt($plain);
    }

    /**
     * Returns the decrypted GRE API client secret (backend use only).
     */
    public function getGreClientSecret()
    {
        $value = $this->attributes['gre_client_secret'] ?? null;
        if (!$value) {
            return null;
        }

        try {
            return decrypt($value);
        } catch (\Illuminate\Contracts\Encryption\DecryptException $e) {
            throw new \App\CoreFacturalo\WS\Gre\Exceptions\GreConfigurationException(
                'No se pudo leer el client_secret guardado; vuelve a ingresarlo en la configuración de la empresa.'
            );
        }
    }

    public function hasGreClientSecret()
    {
        return !empty($this->attributes['gre_client_secret'] ?? null);
    }

    /**
     * Stores the SIRE API client secret encrypted. Null/empty clears it.
     */
    public function setSireClientSecret($plain)
    {
        $this->attributes['sire_client_secret'] = ($plain === null || $plain === '') ? null : encrypt($plain);
    }

    /**
     * Returns the decrypted SIRE API client secret (backend use only).
     */
    public function getSireClientSecret()
    {
        $value = $this->attributes['sire_client_secret'] ?? null;
        if (!$value) {
            return null;
        }

        try {
            return decrypt($value);
        } catch (\Illuminate\Contracts\Encryption\DecryptException $e) {
            throw new \App\CoreFacturalo\WS\Gre\Exceptions\GreConfigurationException(
                'No se pudo leer el client_secret de SIRE guardado; vuelve a ingresarlo en la configuración de la empresa.'
            );
        }
    }

    public function hasSireClientSecret()
    {
        return !empty($this->attributes['sire_client_secret'] ?? null);
    }

    /**
     * Stores the client secret of the "Consulta de validez de comprobantes" API encrypted. Null/empty clears it.
     */
    public function setCpeClientSecret($plain)
    {
        $this->attributes['cpe_client_secret'] = ($plain === null || $plain === '') ? null : encrypt($plain);
    }

    /**
     * Returns the decrypted client secret of the validity API (backend use only).
     */
    public function getCpeClientSecret()
    {
        $value = $this->attributes['cpe_client_secret'] ?? null;
        if (!$value) {
            return null;
        }

        try {
            return decrypt($value);
        } catch (\Illuminate\Contracts\Encryption\DecryptException $e) {
            throw new \App\CoreFacturalo\WS\Gre\Exceptions\GreConfigurationException(
                'No se pudo leer el client_secret de la consulta de validez guardado; vuelve a ingresarlo en la configuración de la empresa.'
            );
        }
    }

    public function hasCpeClientSecret()
    {
        return !empty($this->attributes['cpe_client_secret'] ?? null);
    }

    public function identity_document_type()
    {
        return $this->belongsTo(IdentityDocumentType::class, 'identity_document_type_id');
    }

    public static function active()
    {
        return Company::first();
    }
}
