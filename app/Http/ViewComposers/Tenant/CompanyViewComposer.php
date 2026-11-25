<?php

namespace App\Http\ViewComposers\Tenant;

use App\Models\Tenant\Company;
use App\Models\Tenant\Order;
use Illuminate\Support\Facades\DB;

class CompanyViewComposer
{
    public function compose($view)
    {
        $key = 'vc_company_memo_'.DB::connection('tenant')->getDatabaseName();
        $app = app();

        if (!$app->bound($key)) {
            $app->instance($key, [
                'company' => Company::first(),
                'orders' => Order::where('status_order_id', 1)->count(),
            ]);
        }

        $memo = $app->make($key);
        $view->vc_company = $memo['company'];
        $view->vc_orders = $memo['orders'];
    }
}
