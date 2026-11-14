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
    ];

    protected $hidden = ['gre_client_secret'];

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

    public function identity_document_type()
    {
        return $this->belongsTo(IdentityDocumentType::class, 'identity_document_type_id');
    }

    public static function active()
    {
        return Company::first();
    }
}
