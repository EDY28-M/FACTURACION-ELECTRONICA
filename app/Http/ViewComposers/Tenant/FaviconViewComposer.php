<?php

namespace App\Http\ViewComposers\Tenant;

use App\Models\Tenant\Company;

/**
 * Provides the company's favicon URL to every tenant layout head.
 */
class FaviconViewComposer
{
    public function compose($view)
    {
        $data = $view->getData();

        try {
            $company = $data['vc_company'] ?? Company::query()->select('favicon')->first();
        } catch (\Throwable $e) {
            // No tenant connection (or company table) available: render without a favicon.
            $company = null;
        }
        $path = $company ? $company->favicon : null;

        $view->with('vc_favicon_url', $path ? asset($path) : null);
    }
}
