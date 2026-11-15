<?php

namespace App\Http\Resources\Tenant;

use Illuminate\Http\Resources\Json\JsonResource;
use App\Models\Tenant\Configuration;


class CompanyResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request
     * @return array
     */
    public function toArray($request)
    {
        $configuration = Configuration::first();
        return [
            'id' => $this->id,
            'number' => $this->number,
            'name' => $this->name,
            'trade_name' => $this->trade_name,
            'soap_send_id' => $this->soap_send_id,
            'soap_type_id' => $this->soap_type_id,
            'soap_username' => $this->soap_username,
            'soap_password' => $this->soap_password,
            'soap_url' => $this->soap_url,
            'certificate' => $this->certificate,
            'certificate_due' => $this->certificate_due,
            'logo' => $this->logo,
            'detraction_account' => $this->detraction_account,
            'logo_store' => $this->logo_store,
            'operation_amazonia' => (bool) $this->operation_amazonia,
            'config_system_env' => (bool)$configuration->config_system_env,
            'img_firm' => $this->img_firm,
            'favicon' => $this->favicon,
            'gre_client_id' => $this->gre_client_id,
            'has_gre_client_secret' => $this->hasGreClientSecret(),
            'sire_client_id' => $this->sire_client_id,
            'has_sire_client_secret' => $this->hasSireClientSecret(),
            'cpe_client_id' => $this->cpe_client_id,
            'has_cpe_client_secret' => $this->hasCpeClientSecret(),
        ];
    }
}
