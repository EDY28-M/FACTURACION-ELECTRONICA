<?php

namespace Modules\Dashboard\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Dashboard\Helpers\DashboardData;
use Modules\Dashboard\Helpers\DashboardUtility;
use Modules\Dashboard\Helpers\DashboardSalePurchase;
use Modules\Dashboard\Helpers\DashboardView;
use Modules\Dashboard\Helpers\DashboardStock;
use Modules\Dashboard\Helpers\DashboardInsights;
use App\Models\Tenant\Company;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

class DashboardController extends Controller
{
    public function index()
    {
        $company = Company::select('soap_type_id')->first();
        $soap_company = $company ? $company->soap_type_id : null;

        $bootstrap = $this->buildBootstrap();

        return view('dashboard::index', compact('soap_company', 'bootstrap'));
    }

    /**
     * Initial dashboard payload for the default filter, computed with the same helpers the XHR
     * endpoints use. Returns null on any failure so the Vue component falls back to XHR.
     */
    private function buildBootstrap(): ?array
    {
        try {
            $establishments = DashboardView::getEstablishments();
            $establishment_id = $establishments->isNotEmpty() ? $establishments->first()['id'] : null;
            $tz = config('app.timezone');
            $form = [
                'item_id' => null,
                'establishment_id' => $establishment_id,
                'enabled_expense' => null,
                'enabled_move_item' => false,
                'enabled_transaction_customer' => false,
                'period' => 'last_week',
                'date_start' => now($tz)->subDays(7)->format('Y-m-d'),
                'date_end' => now($tz)->format('Y-m-d'),
                'month_start' => now($tz)->format('Y-m'),
                'month_end' => now($tz)->format('Y-m'),
                'customer_id' => null,
            ];

            $stock = ['data' => [], 'meta' => ['total' => 0, 'per_page' => 5, 'current_page' => 1]];
            if ($establishment_id) {
                $stock = $this->stockByProduct(Request::create('/', 'GET', ['establishment_id' => $establishment_id]))
                    ->toResponse(request())->getData(true);
            }

            $company = Company::active();

            $payload = [
                'stamp' => uniqid('', true),
                'form' => $form,
                'establishments' => $establishments,
                'global' => (new DashboardData())->globalData(),
                'data' => (new DashboardData())->data($form),
                'additional' => (new DashboardSalePurchase())->data($form),
                'utilities' => (new DashboardUtility())->data($form),
                'insights' => (new DashboardInsights())->data($form),
                'stock' => $stock,
                // Only what the dashboard renders: the full company record carries SOAP credentials.
                'company' => ['certificate_due' => $company ? $company->certificate_due : null],
            ];

            $json = json_encode($payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

            return $json === false ? null : ['json' => $json];
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    public function filter()
    {
        return [
            'establishments' => DashboardView::getEstablishments()
        ];
    }

    public function globalData()
    {
        return response()->json((new DashboardData())->globalData(), 200);
    }

    public function data(Request $request)
    {
        return [
            'data' => (new DashboardData())->data($request->all()),
        ];
    }

    public function data_aditional(Request $request)
    {
        return [
            'data' => (new DashboardSalePurchase())->data($request->all()),
        ];
    }

    public function insights(Request $request)
    {
        return [
            'data' => (new DashboardInsights())->data($request->all()),
        ];
    }

    public function stockByProduct(Request $request)
    {
        return  (new DashboardStock())->data($request);
    }


    public function utilities(Request $request)
    {
        return [
            'data' => (new DashboardUtility())->data($request->all()),
        ];
    }

    public function df()
    {
        $path = app_path();
        //df -m -h --output=used,avail,pcent /

        $used = Process::fromShellCommandline('df -m -h --output=used /');
        $used->run();
        if (!$used->isSuccessful()) {
            return ['error'];
            throw new ProcessFailedException($used);
        }
        $disc_used = $used->getOutput();
        $array[] = str_replace("\n","",$disc_used);

        $avail = Process::fromShellCommandline('df -m -h --output=avail /');
        $avail->run();
        if (!$avail->isSuccessful()) {
            return ['error'];
            throw new ProcessFailedException($avail);
        }
        $disc_avail = $avail->getOutput();
        $array[] = str_replace("\n","",$disc_avail);

        $pcent = Process::fromShellCommandline('df -m -h --output=pcent /');
        $pcent->run();
        if (!$pcent->isSuccessful()) {
            return ['error'];
            throw new ProcessFailedException($pcent);
        }
        $disc_pcent = $pcent->getOutput();
        $array[] = str_replace("\n","",$disc_pcent);

        return $array;


    }

}
