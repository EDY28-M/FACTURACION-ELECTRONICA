<?php

namespace Modules\Treasury\Http\Controllers;

use App\Models\Tenant\Catalogs\CurrencyType;
use App\Models\Tenant\Establishment;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Modules\Treasury\Services\DetractionService;
use Modules\Treasury\Services\InstrumentService;
use Modules\Treasury\Services\ReceivablePayableService;
use Modules\Treasury\Services\TreasuryBalanceService;

class DashboardController extends Controller
{
    private $balanceService;
    private $receivablePayableService;
    private $detractionService;
    private $instrumentService;

    public function __construct(
        TreasuryBalanceService $balanceService,
        ?ReceivablePayableService $receivablePayableService = null,
        ?DetractionService $detractionService = null,
        ?InstrumentService $instrumentService = null
    ) {
        $this->balanceService = $balanceService;
        $this->receivablePayableService = $receivablePayableService;
        $this->detractionService = $detractionService;
        $this->instrumentService = $instrumentService;
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

        $summary['alerts'] = array_merge($summary['alerts'] ?? [], [
            'overdue_installments_count' => 0,
            'due_soon_installments_count' => 0,
            'unbancarized_payments_count' => 0,
            'unbancarized_obligations_count' => 0,
        ]);

        if ($this->receivablePayableService !== null) {
            try {
                $user = auth()->user();
                $isAdmin = $user !== null && ($user->type ?? null) === 'admin';
                $alertFilters = [
                    'establishment_id' => !$isAdmin
                        ? ($user && !empty($user->establishment_id) ? (int) $user->establishment_id : 0)
                        : (!empty($validated['establishment_id']) ? (int) $validated['establishment_id'] : null),
                    'user_id' => !$isAdmin ? ($user ? (int) $user->id : null) : null,
                    'is_admin' => $isAdmin,
                    'currency_type_id' => $validated['currency_type_id'] ?? null,
                    'date_start' => $dates['date_start'],
                    'date_end' => $dates['date_end'],
                ];

                $rpAlerts = $this->receivablePayableService->getAlerts($alertFilters);
                $summary['alerts']['overdue_installments_count'] = (int) ($rpAlerts['summary']['overdue_count'] ?? 0);
                $summary['alerts']['due_soon_installments_count'] = (int) ($rpAlerts['summary']['due_soon_count'] ?? 0);
                $summary['alerts']['unbancarized_payments_count'] = (int) ($rpAlerts['summary']['unbancarized_payments_count'] ?? 0);
                $summary['alerts']['unbancarized_obligations_count'] = (int) ($rpAlerts['summary']['unbancarized_count'] ?? 0);
            } catch (\Throwable $e) {
                Log::warning('Treasury dashboard alerts failed', ['error' => $e->getMessage()]);
            }
        }

        $summary['alerts']['overdue_detractions'] = [];
        $summary['alerts']['overdue_detractions_count'] = 0;
        $summary['alerts']['overdue_detractions_amount_pen'] = 0.0;

        if ($this->detractionService !== null) {
            try {
                $user = auth()->user();
                $isAdmin = $user !== null && ($user->type ?? null) === 'admin';

                // Bounded, idempotent lazy sync (at most once per throttle window per tenant) so brand-new
                // detractions show up without opening Detracciones.
                $this->detractionService->syncThrottled(100);

                $detractionAlerts = $this->detractionService->getOverdueAlerts([
                    'is_admin' => $isAdmin,
                    'user_id' => !$isAdmin ? ($user ? (int) $user->id : null) : null,
                    'establishment_id' => !$isAdmin
                        ? ($user && !empty($user->establishment_id) ? (int) $user->establishment_id : 0)
                        : (!empty($validated['establishment_id']) ? (int) $validated['establishment_id'] : null),
                ]);

                $summary['alerts']['overdue_detractions'] = $detractionAlerts['items'];
                $summary['alerts']['overdue_detractions_count'] = (int) $detractionAlerts['count'];
                $summary['alerts']['overdue_detractions_amount_pen'] = (float) $detractionAlerts['amount_pen'];
            } catch (\Throwable $e) {
                Log::warning('Treasury dashboard detraction alerts failed', ['error' => $e->getMessage()]);
            }
        }

        $summary['alerts']['instruments'] = [
            'counts' => [
                'cheque_presentation_soon' => 0,
                'cheque_presentation_expired' => 0,
                'bill_due_soon' => 0,
                'bill_overdue' => 0,
                'protest_deadline_soon' => 0,
                'protest_deadline_expired' => 0,
            ],
            'total' => 0,
            'items' => [],
        ];

        if ($this->instrumentService !== null) {
            try {
                $user = auth()->user();
                $isAdmin = $user !== null && ($user->type ?? null) === 'admin';

                $summary['alerts']['instruments'] = $this->instrumentService->getAlerts([
                    'is_admin' => $isAdmin,
                    'user_id' => $user ? (int) $user->id : null,
                    'establishment_id' => !$isAdmin
                        ? ($user && !empty($user->establishment_id) ? (int) $user->establishment_id : 0)
                        : (!empty($validated['establishment_id']) ? (int) $validated['establishment_id'] : null),
                ]);
            } catch (\Throwable $e) {
                Log::warning('Treasury dashboard instrument alerts failed', ['error' => $e->getMessage()]);
            }
        }

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
