<?php

namespace Modules\Treasury\Http\Controllers;

use App\Models\Tenant\BankAccount;
use App\Models\Tenant\Cash;
use App\Models\Tenant\Company;
use App\Models\Tenant\Establishment;
use Barryvdh\DomPDF\Facade\Pdf as PDF;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Modules\Treasury\Exports\CashBookFormat11Export;
use Modules\Treasury\Exports\CashBookFormat12Export;
use Modules\Treasury\Exports\CashFlowExport;
use Modules\Treasury\Http\Requests\CashBookReportRequest;
use Modules\Treasury\Http\Requests\CashFlowReportRequest;
use Modules\Treasury\Services\CashBookReport;
use Modules\Treasury\Services\CashFlowService;

/**
 * Treasury Phase 6 Reports controller (Real & Projected Cash Flow, Libro Caja y Bancos 1.1 & 1.2).
 * Thin controller: validates via FormRequest, builds explicit user scope, and delegates to services.
 */
class ReportController extends Controller
{
    private $cashFlowService;
    private $cashBookReport;

    public function __construct(CashFlowService $cashFlowService, CashBookReport $cashBookReport)
    {
        $this->cashFlowService = $cashFlowService;
        $this->cashBookReport = $cashBookReport;
    }

    // ---------------------------------------------------------------------------------------------------------
    // Cash Flow (Flujo de caja Real & Proyectado)
    // ---------------------------------------------------------------------------------------------------------

    public function cashFlowIndex()
    {
        return view('treasury::reports.cash_flow');
    }

    public function cashFlowTables(): JsonResponse
    {
        $scope = $this->scope(auth()->user());
        $isAdmin = $scope['is_admin'];
        $userEstId = (int) ($scope['establishment_id'] ?? 0);

        $establishments = $this->scopedEstablishments($isAdmin, $userEstId);

        return response()->json([
            'establishments' => $establishments,
            'currencies' => [
                ['id' => 'ALL', 'description' => 'Consolidado (PEN)'],
                ['id' => 'PEN', 'description' => 'Soles (PEN)'],
                ['id' => 'USD', 'description' => 'Dólares (USD)'],
            ],
            'destination_types' => [
                ['value' => 'all', 'label' => 'Todas las cuentas (Caja y Bancos)'],
                ['value' => 'bank_account', 'label' => 'Solo cuentas bancarias'],
                ['value' => 'cash', 'label' => 'Solo caja (Efectivo)'],
            ],
            'granularities' => [
                ['value' => 'week', 'label' => 'Semanal'],
                ['value' => 'month', 'label' => 'Mensual'],
            ],
            'is_admin' => $isAdmin,
            'default_establishment_id' => !$isAdmin && $userEstId > 0 ? $userEstId : null,
        ]);
    }

    public function cashFlowRecords(CashFlowReportRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $scope = $this->scope(auth()->user());
        $mode = (string) ($validated['mode'] ?? 'real');

        $data = $mode === 'projected'
            ? $this->cashFlowService->getProjectedCashFlow($validated, $scope)
            : $this->cashFlowService->getRealCashFlow($validated, $scope);

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    public function cashFlowExportExcel(CashFlowReportRequest $request)
    {
        $validated = $request->validated();
        $scope = $this->scope(auth()->user());
        $mode = (string) ($validated['mode'] ?? 'real');

        $report = $mode === 'projected'
            ? $this->cashFlowService->getProjectedCashFlow($validated, $scope)
            : $this->cashFlowService->getRealCashFlow($validated, $scope);

        $company = $this->companyMeta();
        $prefix = $mode === 'projected' ? 'Flujo_Caja_Proyectado_' : 'Flujo_Caja_Real_';
        $filename = $prefix . date('Ymd') . '.xlsx';

        return (new CashFlowExport($report, $company))->download($filename);
    }

    public function cashFlowExportPdf(CashFlowReportRequest $request)
    {
        $validated = $request->validated();
        $scope = $this->scope(auth()->user());
        $mode = (string) ($validated['mode'] ?? 'real');

        $report = $mode === 'projected'
            ? $this->cashFlowService->getProjectedCashFlow($validated, $scope)
            : $this->cashFlowService->getRealCashFlow($validated, $scope);

        $company = $this->companyMeta();
        $pdf = PDF::loadView('treasury::reports.cash_flow_pdf', [
            'report' => $report,
            'company' => $company,
        ])->setPaper('a4', 'landscape');

        $prefix = $mode === 'projected' ? 'Flujo_Caja_Proyectado_' : 'Flujo_Caja_Real_';

        return $pdf->download($prefix . date('Ymd') . '.pdf');
    }

    // ---------------------------------------------------------------------------------------------------------
    // Libro Caja y Bancos (Formato 1.1 & Formato 1.2)
    // ---------------------------------------------------------------------------------------------------------

    public function cashBookIndex()
    {
        return view('treasury::reports.cash_book');
    }

    public function cashBookTables(): JsonResponse
    {
        $scope = $this->scope(auth()->user());
        $isAdmin = $scope['is_admin'];
        $userEstId = (int) ($scope['establishment_id'] ?? 0);
        $userId = (int) ($scope['user_id'] ?? 0);

        $establishments = $this->scopedEstablishments($isAdmin, $userEstId);
        $bankAccounts = $this->scopedBankAccounts($isAdmin, $userEstId);
        $cashes = $this->scopedCashes($isAdmin, $userEstId, $userId);

        return response()->json([
            'establishments' => $establishments,
            'bank_accounts' => $bankAccounts,
            'cashes' => $cashes,
            'is_admin' => $isAdmin,
            'default_establishment_id' => !$isAdmin && $userEstId > 0 ? $userEstId : null,
        ]);
    }

    public function cashBookRecords(CashBookReportRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $scope = $this->scope(auth()->user());
        $format = (string) $validated['format'];

        $data = $format === '1.2'
            ? $this->cashBookReport->buildFormat12($validated, $scope)
            : $this->cashBookReport->buildFormat11($validated, $scope);

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    public function cashBookExportExcel(CashBookReportRequest $request)
    {
        $validated = $request->validated();
        $scope = $this->scope(auth()->user());
        $format = (string) $validated['format'];

        if ($format === '1.2') {
            $report = $this->cashBookReport->buildFormat12($validated, $scope);

            return (new CashBookFormat12Export($report))->download('Libro_Caja_Bancos_F12_' . date('Ymd') . '.xlsx');
        }

        $report = $this->cashBookReport->buildFormat11($validated, $scope);

        return (new CashBookFormat11Export($report))->download('Libro_Caja_Bancos_F11_' . date('Ymd') . '.xlsx');
    }

    public function cashBookExportPdf(CashBookReportRequest $request)
    {
        $validated = $request->validated();
        $scope = $this->scope(auth()->user());
        $format = (string) $validated['format'];

        if ($format === '1.2') {
            $report = $this->cashBookReport->buildFormat12($validated, $scope);
            $pdf = PDF::loadView('treasury::reports.cash_book_12_pdf', ['report' => $report])
                ->setPaper('a4', 'landscape');

            return $pdf->download('Libro_Caja_Bancos_F12_' . date('Ymd') . '.pdf');
        }

        $report = $this->cashBookReport->buildFormat11($validated, $scope);
        $pdf = PDF::loadView('treasury::reports.cash_book_11_pdf', ['report' => $report])
            ->setPaper('a4', 'portrait');

        return $pdf->download('Libro_Caja_Bancos_F11_' . date('Ymd') . '.pdf');
    }

    // ---------------------------------------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------------------------------------

    private function scopedEstablishments(bool $isAdmin, int $userEstId): array
    {
        $query = Establishment::query()->orderBy('id');
        if (!$isAdmin) {
            if ($userEstId <= 0) {
                return [];
            }
            $query->where('id', $userEstId);
        }

        return $query->get()->map(function ($row) {
            return [
                'id' => (int) $row->id,
                'description' => (string) $row->description,
            ];
        })->values()->all();
    }

    private function scopedBankAccounts(bool $isAdmin, int $userEstId): array
    {
        $query = BankAccount::with('bank')->orderBy('id');
        if (!$isAdmin) {
            if ($userEstId <= 0) {
                return [];
            }
            $query->where(function ($q) use ($userEstId) {
                $q->whereNull('establishment_id')->orWhere('establishment_id', $userEstId);
            });
        }

        return $query->get()->map(function ($row) {
            $bank = $row->bank ? $row->bank->description : 'Banco';

            return [
                'id' => (int) $row->id,
                'description' => "{$bank} - {$row->currency_type_id} - {$row->description}",
                'number' => (string) ($row->number ?? ''),
                'currency_type_id' => (string) $row->currency_type_id,
                'establishment_id' => $row->establishment_id ? (int) $row->establishment_id : null,
            ];
        })->values()->all();
    }

    private function scopedCashes(bool $isAdmin, int $userEstId, int $userId): array
    {
        if (!$isAdmin && ($userEstId <= 0 || $userId <= 0)) {
            return [];
        }

        $query = Cash::with('user')
            ->orderBy('state', 'desc')
            ->orderBy('date_opening', 'desc')
            ->orderBy('id', 'desc');

        if (!$isAdmin) {
            $query->where('user_id', $userId);
        }

        return $query->get()->filter(function ($row) use ($isAdmin, $userEstId) {
            if ($isAdmin) {
                return true;
            }
            $cashEstId = ($row->user && $row->user->establishment_id) ? (int) $row->user->establishment_id : null;

            return $cashEstId === null || $cashEstId === $userEstId;
        })->map(function ($row) {
            $owner = $row->user ? $row->user->name : ('Usuario #' . $row->user_id);
            $ref = $row->reference_number ? " ({$row->reference_number})" : '';
            $stateLabel = $row->state ? 'Abierta' : 'Cerrada';

            return [
                'id' => (int) $row->id,
                'description' => "Caja POS{$ref} - {$owner} [{$stateLabel}]",
                'state' => (bool) $row->state,
                'user_id' => (int) $row->user_id,
                'establishment_id' => ($row->user && $row->user->establishment_id) ? (int) $row->user->establishment_id : null,
            ];
        })->values()->all();
    }

    private function companyMeta(): array
    {
        try {
            $schema = DB::connection('tenant')->getSchemaBuilder();
            if ($schema->hasTable('companies')) {
                $cols = [];
                if ($schema->hasColumn('companies', 'number')) {
                    $cols[] = 'number';
                }
                if ($schema->hasColumn('companies', 'name')) {
                    $cols[] = 'name';
                }
                if (!empty($cols)) {
                    $row = DB::connection('tenant')->table('companies')->first($cols);
                    if ($row) {
                        return [
                            'ruc' => !empty($row->number) ? (string) $row->number : '-',
                            'name' => !empty($row->name) ? (string) $row->name : '-',
                        ];
                    }
                }
            }
        } catch (\Throwable $e) {
            // Fall back to empty metadata if company table is unavailable.
        }

        return ['ruc' => '-', 'name' => '-'];
    }

    private function scope($user): array
    {
        return [
            'is_admin' => $user !== null && ($user->type ?? null) === 'admin',
            'user_id' => $user ? (int) $user->id : null,
            'establishment_id' => $user && !empty($user->establishment_id) ? (int) $user->establishment_id : null,
        ];
    }
}
