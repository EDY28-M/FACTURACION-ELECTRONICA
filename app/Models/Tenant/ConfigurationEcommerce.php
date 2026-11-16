<?php

namespace App\Models\Tenant;

use App\Casts\EncryptedSecret;

class ConfigurationEcommerce extends ModelTenant
{
    protected $table = "configuration_ecommerce";

    protected $fillable = [
        'information_contact_name',
        'information_contact_email',
        'information_contact_phone',
        'information_contact_address',
        'token_private_culqui',
        'token_public_culqui',
        'link_youtube',
        'link_twitter',
        'link_facebook',
        'tag_shipping',
        'tag_dollar',
        'tag_support'
    ];

    protected $casts = [
        'token_private_culqui' => EncryptedSecret::class,
    ];

}
