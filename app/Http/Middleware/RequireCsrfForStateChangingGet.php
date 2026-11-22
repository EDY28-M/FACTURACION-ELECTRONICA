<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Some legacy tenant actions still use GET (send, anular, close, voided).
 * They cannot rely on the framework CSRF check, so require the same
 * X-CSRF-TOKEN header that the Vue/axios client already sends.
 */
class RequireCsrfForStateChangingGet
{
    private const PATTERNS = [
        'documents/send/*',
        'documents/re_store/*',
        'documents/send_server/*',
        'documents/check_server/*',
        'documents/change_to_registered_status/*',
        'purchases/anular/*',
        'purchases/delete/*',
        'quotations/anular/*',
        'cash/close/*',
        'expenses/*/voided',
        'finances/income/voided/*',
        'order-notes/voided/*',
        'purchase-orders/anular/*',
        'fixed-asset/purchases/voided/*',
        'contracts/voided/*',
        'contracts/state-type/*',
        'items/images/delete/*',
        'inventory/regularize_stock',
        'items/enable/*',
        'items/disable/*',
        'persons/enabled/*',
        'configurations/addSeeder',
        'configurations/preprinted/addSeeder',
        'client_payments/cancel_payment/*',
        'contracts/changed/*',
        'contracts/generate-quotation/*',
        'documents/regularize-lots/*',
        'documents/consult_cdr/*',
        'padron/charges_data',
        'padron/demo',
        'purchase-orders/generate/*',
        'purchase-orders/anular/*',
        'purchase-orders/sale-opportunity/*',
        'summaries/status/*',
        'voided/status/*',
        'voided/status_masive',
        'quotations/state-type/*',
        'quotations/changed/*',
        'sale-notes/changed/*',
        'auto-update/pull/*',
        'auto-update/composer/install',
        'auto-update/artisan/migrate',
        'auto-update/artisan/migrate/tenant',
        'auto-update/artisan/clear',
    ];

    public function handle(Request $request, Closure $next)
    {
        if (!in_array($request->method(), ['GET', 'HEAD'], true)) {
            return $next($request);
        }

        $matches = false;
        foreach (self::PATTERNS as $pattern) {
            if ($request->is($pattern)) {
                $matches = true;
                break;
            }
        }

        if (!$matches) {
            return $next($request);
        }

        $token = $request->header('X-CSRF-TOKEN');
        if (is_string($token) && $token !== '' && hash_equals(csrf_token(), $token)) {
            return $next($request);
        }

        if ($request->expectsJson() || $request->ajax()) {
            return new JsonResponse(['message' => 'CSRF token mismatch.'], 419);
        }

        return new Response('CSRF token mismatch.', 419);
    }
}
