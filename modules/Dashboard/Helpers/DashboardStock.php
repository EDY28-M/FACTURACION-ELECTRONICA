<?php

namespace Modules\Dashboard\Helpers;

use App\Models\Tenant\Establishment;
use Illuminate\Support\Facades\DB;
use Modules\Dashboard\Http\Resources\DashboardStockCollection;

class DashboardStock
{
    public function data($request)
    {
        return $this->stock_by_products($request);
    }

    private function stock_by_products($request)
    {
        $establishment_id = $request->establishment_id;

        if (!$establishment_id) {
            $establishment_id = optional(Establishment::select('id')->first())->id;
        }

        // Uses tenant SQL view v_low_stock_products (2 queries total vs 47 N+1 queries)
        $products = DB::connection('tenant')
            ->table('v_low_stock_products')
            ->where('establishment_id', $establishment_id)
            ->orderBy('stock')
            ->paginate(config('tenant.items_per_page_simple_d_table'));

        return new DashboardStockCollection($products);
    }
}
