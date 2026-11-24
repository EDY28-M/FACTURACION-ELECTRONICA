<?php

namespace Modules\Treasury\Http\Controllers;

use App\Models\Tenant\BankAccount;
use App\Models\Tenant\Cash;
use App\Models\Tenant\Company;
use App\Models\Tenant\Establishment;
use Barryvdh\DomPDF\Facade\Pdf as PDF;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Modules\Treasury\Exports\CashBookFormat11Export;
use Modules\Treasury\Exports\CashBookFormat12Export;
use Modules\Treasury\Exports\CashFlowExport;
use Modules\Treasury\Http\Requests\CashBookReportRequest;
use Modules\Treasury\Http\Requests\CashFlowReportRequest;
use Modules\Treasury\Http\Requests\PleExportRequest;
use Modules\Treasury\Services\CashBookReport;
use Modules\Treasury\Services\CashFlowService;
use Modules\Treasury\Services\PleCashBookExporter;
use Modules\Treasury\Support\CashSelector;
use Modules\Treasury\Support\PleExportException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Treasury Phase 6 Reports controller (Real & Projected Cash Flow, Libro Caja y Bancos 1.1 & 1.2).
 * Thin controller: validates via FormRequest, builds explicit user scope, and delegates to services.
 */
class ReportController extends Controller
{
    private $cashFlowService;
    private $cashBookReport;
    private $pleExporter;

    public function __construct(CashFlowService $cashFlowService, CashBookReport $cashBookReport, PleCashBookExporter $pleExporter)
    {
        $this->cashFlowService = $cashFlowService;
        $this->cashBookReport = $cashBookReport;
        $this->pleExporter = $pleExporter;
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

    public function cashBookTables(?Request $request = null): JsonResponse
    {
        $scope = $this->scope(auth()->user());
        $isAdmin = $scope['is_admin'];
        $userEstId = (int) ($scope['establishment_id'] ?? 0);
        $userId = (int) ($scope['user_id'] ?? 0);

        $establishments = $this->scopedEstablishments($isAdmin, $userEstId);
        $bankAccounts = $this->scopedBankAccounts($isAdmin, $userEstId);
        $cashes = $this->scopedCashes($isAdmin, $userEstId, $userId, CashSelector::filtersFrom($request ? $request->all() : null));

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
    // PLE TXT (Libro Caja y Bancos 010100 / 010200)
    // ---------------------------------------------------------------------------------------------------------

    /**
     * Dry run: file names, line counts and the blockers (missing Tabla 1 / Tabla 3 codes...) without any content.
     */
    public function pleCashBookPreview(PleExportRequest $request): JsonResponse
    {
        if ($denied = $this->pleAdminOnly()) {
            return $denied;
        }

        $validated = $request->validated();
        $result = $this->pleExporter->build($validated['period'], $this->scope(auth()->user()), $validated);

        return response()->json([
            'success' => true,
            'data' => [
                'period' => $result['period'],
                'ruc' => $result['ruc'],
                'files' => array_map(function ($file) {
                    unset($file['content']);

                    return $file;
                }, $result['files']),
                'blockers' => $result['blockers'],
                'warnings' => $result['warnings'],
                'can_export' => empty($result['blockers']),
            ],
        ]);
    }

    public function pleCashBookFile(PleExportRequest $request)
    {
        if ($denied = $this->pleAdminOnly()) {
            return $denied;
        }

        $validated = $request->validated();
        if (empty($validated['format'])) {
            return response()->json(['message' => 'Indique el formato (1.1 o 1.2).', 'errors' => ['format' => ['Indique el formato (1.1 o 1.2).']]], 422);
        }

        try {
            // Only the blockers of the requested format apply: a 1.2 problem must not block the 1.1 (cash) file.
            $result = $this->pleExporter->buildOrFail($validated['period'], $this->scope(auth()->user()), $validated, $validated['format']);
        } catch (PleExportException $e) {
            return $this->pleRefusal($e);
        }

        $currency = $validated['format'] === '1.1' ? 'PEN' : ($validated['currency'] ?? 'PEN');
        foreach ($result['files'] as $file) {
            if ($file['format'] === $validated['format'] && $file['currency'] === $currency) {
                return new Response($file['content'], 200, [
                    'Content-Type' => 'text/plain; charset=UTF-8',
                    'Content-Disposition' => 'attachment; filename="' . $file['filename'] . '"',
                ]);
            }
        }

        return response()->json(['message' => 'No hay un archivo PLE para ese formato y moneda en el periodo.'], 404);
    }

    public function pleCashBookZip(PleExportRequest $request)
    {
        if ($denied = $this->pleAdminOnly()) {
            return $denied;
        }

        $validated = $request->validated();

        try {
            $result = $this->pleExporter->buildOrFail($validated['period'], $this->scope(auth()->user()), $validated);
        } catch (PleExportException $e) {
            return $this->pleRefusal($e);
        }

        $path = tempnam(sys_get_temp_dir(), 'ple');
        if ($path === false) {
            return $this->zipFailure(null);
        }

        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::OVERWRITE) !== true) {
            return $this->zipFailure($path);
        }
        foreach ($result['files'] as $file) {
            if (!$zip->addFromString($file['filename'], $file['content'])) {
                $zip->close();

                return $this->zipFailure($path);
            }
        }
        if (!$zip->close()) {
            return $this->zipFailure($path);
        }

        $name = 'PLE_Libro_Caja_Bancos_' . $result['ruc'] . '_' . str_replace('-', '', $result['period']) . '.zip';

        $download = new BinaryFileResponse($path, 200, ['Content-Type' => 'application/zip'], false);
        $download->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $name);

        return $download->deleteFileAfterSend(true);
    }

    /**
     * The PLE is the company's book: the exporter scopes by user, so a non-admin would download (or preview) a
     * partial book presented as complete. Only administrators export it.
     */
    private function pleAdminOnly(): ?JsonResponse
    {
        $user = auth()->user();
        if ($user !== null && ($user->type ?? null) === 'admin') {
            return null;
        }

        return response()->json([
            'success' => false,
            'message' => 'Solo un administrador puede generar el PLE del libro Caja y Bancos.',
        ], 403);
    }

    /** Removes the temporary file (when it exists) and answers 500 so a broken ZIP is never sent. */
    private function zipFailure(?string $path): JsonResponse
    {
        if ($path !== null && is_file($path)) {
            @unlink($path);
        }

        return response()->json(['message' => 'No se pudo crear el archivo ZIP.'], 500);
    }

    private function pleRefusal(PleExportException $e): JsonResponse
    {
        $messages = [];
        foreach ($e->blockers() as $blocker) {
            $messages[] = $blocker['message'] . (empty($blocker['items']) ? '' : ' ' . implode('; ', $blocker['items']) . '.');
        }

        return response()->json([
            'message' => $messages[0] ?? $e->getMessage(),
            'errors' => ['ple' => $messages],
            'blockers' => $e->blockers(),
        ], 422);
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

    private function scopedCashes(bool $isAdmin, int $userEstId, int $userId, array $filters = []): array
    {
        return CashSelector::options($isAdmin, $userEstId, $userId, $filters);
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
