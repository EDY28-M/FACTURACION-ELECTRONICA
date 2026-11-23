<?php

namespace App\Http\Resources\Tenant;

use Illuminate\Http\Resources\Json\JsonResource;

class ConfigurationEcommerceResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request
     * @return array
     */
    public function toArray($request) {
        return [
            'id' => $this->id,
            'information_contact_name' => $this->information_contact_name,
            'information_contact_email' =>  $this->information_contact_email,
            'information_contact_phone' =>  $this->information_contact_phone,
            'information_contact_address' =>  $this->information_contact_address,
            // The private key is write-only: only its presence is ever exposed.
            'has_token_private_culqui' => (bool) $this->token_private_culqui,
            'token_public_culqui' => $this->token_public_culqui,
            'card_enabled' => (bool) $this->token_public_culqui && (bool) $this->token_private_culqui,
            'culqi_mode' => self::culqiMode($this->token_public_culqui),
            // The store keeps the contact placeholders until the owner edits them.
            'contact_is_default' => $this->information_contact_email === 'admin@mail.com'
                || $this->information_contact_name === 'Admin',

            'logo' => $this->logo,
            'logo_url' => $this->logo ? '/storage/uploads/logos/'.basename($this->logo) : null,
            'link_youtube' => $this->link_youtube,
            'link_twitter' => $this->link_twitter,
            'link_facebook' => $this->link_facebook,
            'tag_shipping' => $this->tag_shipping,
            'tag_dollar' => $this->tag_dollar,
            'tag_support' => $this->tag_support,

        ];
    }

    /**
     * "test" / "live" from the public key prefix (pk_test_… / pk_live_…), null when unknown.
     */
    public static function culqiMode($publicKey): ?string
    {
        if (is_string($publicKey) && preg_match('/^(?:pk|sk)_(test|live)_/', $publicKey, $m)) {
            return $m[1];
        }

        return null;
    }
}
