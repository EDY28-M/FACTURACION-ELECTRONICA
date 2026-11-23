<?php

namespace Modules\Treasury\Http\Controllers;

use App\Models\Tenant\Catalogs\CurrencyType;
use App\Models\Tenant\Establishment;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Treasury\Services\TreasuryBalanceService;

class DashboardController extends Controller
{
    private $balanceService;

    public function __construct(TreasuryBalanceService $balanceService)
    {
        $this->balanceService = $balanceService;
    }

    public function index()
    {
        return view('treasury::dashboard.index');
    }

    public function tables()
    {
        $establishments = Establishment::all()->map(function ($row) {
            return [
                'id' => $row->id,
                'description' => $row->description,
            ];
        })->values();

        $currency_types = CurrencyType::whereActive()->get(['id', 'description', 'symbol']);

        return compact('establishments', 'currency_types');
    }

    public function records(Request $request)
    {
        $validated = $request->validate([
            'establishment_id' => ['nullable', 'integer'],
            'currency_type_id' => ['nullable', 'string', 'max:3'],
            'period' => ['nullable', 'in:month,between_months,date,between_dates'],
            'month_start' => ['nullable', 'date_format:Y-m'],
            'month_end' => ['nullable', 'date_format:Y-m'],
            'date_start' => ['nullable', 'date_format:Y-m-d'],
            'date_end' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $dates = $this->resolvePeriodDates($validated);

        $summary = $this->balanceService->getSummary([
            'establishment_id' => $validated['establishment_id'] ?? null,
            'currency_type_id' => $validated['currency_type_id'] ?? null,
            'date_start' => $dates['date_start'],
            'date_end' => $dates['date_end'],
        ]);

        return response()->json([
            'success' => true,
            'data' => $summary,
        ]);
    }

    private function resolvePeriodDates(array $validated): array
    {
        $period = $validated['period'] ?? null;
        $dateStart = null;
        $dateEnd = null;

        switch ($period) {
            case 'month':
                if (!empty($validated['month_start'])) {
                    $dateStart = Carbon::parse($validated['month_start'] . '-01')->format('Y-m-d');
                    $dateEnd = Carbon::parse($validated['month_start'] . '-01')->endOfMonth()->format('Y-m-d');
                }
                break;
            case 'between_months':
                if (!empty($validated['month_start']) && !empty($validated['month_end'])) {
                    $dateStart = Carbon::parse($validated['month_start'] . '-01')->format('Y-m-d');
                    $dateEnd = Carbon::parse($validated['month_end'] . '-01')->endOfMonth()->format('Y-m-d');
                }
                break;
            case 'date':
                if (!empty($validated['date_start'])) {
                    $dateStart = $validated['date_start'];
                    $dateEnd = $validated['date_start'];
                }
                break;
            case 'between_dates':
                $dateStart = $validated['date_start'] ?? null;
                $dateEnd = $validated['date_end'] ?? null;
                break;
        }

        return [
            'date_start' => $dateStart,
            'date_end' => $dateEnd,
        ];
    }
}
