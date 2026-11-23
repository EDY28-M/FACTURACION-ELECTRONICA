<?php

namespace Modules\Ecommerce\Http\ViewComposers;

use App\Models\Tenant\Company;
use App\Models\Tenant\ConfigurationEcommerce;

class InformationContactViewComposer
{
    public function compose($view)
    {
        $information = ConfigurationEcommerce::first() ?? new ConfigurationEcommerce();
        $company = $this->company();

        // Social links are printed as hrefs: never let a stored non-http(s) value reach the page.
        foreach (['link_facebook', 'link_twitter', 'link_youtube'] as $field) {
            $information->{$field} = self::safeUrl($information->{$field});
        }

        $view->information = $information;
        // Card payments need both Culqi keys; with only one of them the buyer would see a button that cannot charge.
        $view->cardEnabled = (bool) $information->token_public_culqui && (bool) $information->token_private_culqui;
        $view->storeName = $this->storeName($company);
        $view->storeLogoUrl = $this->storeLogoUrl($information, $company);
    }

    /**
     * Returns the URL only when it is an absolute http(s) URL; null otherwise (the link is hidden).
     */
    public static function safeUrl($value): ?string
    {
        $value = trim((string) $value);
        if ($value === '' || ! preg_match('#^https?://[^\s<>"\']+$#i', $value)) {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_URL) ? $value : null;
    }

    private function company()
    {
        try {
            return Company::query()->first();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Logo resolution: store logo, then company logo, otherwise null
     * (the header renders the store name as text).
     */
    private function storeLogoUrl($information, $company): ?string
    {
        $logo = trim((string) $information->logo);
        if ($logo === '') {
            $logo = trim((string) optional($company)->logo);
        }

        // basename() keeps stored values from escaping the logos directory.
        return $logo !== '' ? asset('storage/uploads/logos/'.basename($logo)) : null;
    }

    private function storeName($company): string
    {
        $name = trim((string) optional($company)->trade_name);
        if ($name === '') {
            $name = trim((string) optional($company)->name);
        }

        return $name !== '' ? $name : 'Tienda virtual';
    }
}
