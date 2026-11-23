<?php

namespace Modules\Treasury\Http\Controllers;

use App\Models\Tenant\Cash;
use App\Models\Tenant\Establishment;
use Barryvdh\DomPDF\Facade\Pdf as PDF;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Modules\Treasury\Http\Requests\StoreCashCountRequest;
use Modules\Treasury\Services\CashCountService;
use Modules\Treasury\Support\CashSelector;

/**
 * Cash Count / Arqueo de caja controller (Phase 6).
 * Thin controller: validates, builds explicit user scope, and delegates to CashCountService.
 */
class CashCountController extends Controller
{
    private $service;

    public function __construct(CashCountService $service)
    {
        $this->service = $service;
    }

    public function index()
    {
        return view('treasury::cash_counts.index');
    }

    public function tables(?Request $request = null): JsonResponse
    {
        $scope = $this->scope(auth()->user());
        $isAdmin = $scope['is_admin'];
        $userEstId = (int) ($scope['establishment_id'] ?? 0);
        $userId = (int) ($scope['user_id'] ?? 0);

        $establishments = [];
        if ($isAdmin || $userEstId > 0) {
            $estQuery = Establishment::query()->orderBy('id');
            if (!$isAdmin) {
                $estQuery->where('id', $userEstId);
            }
            $establishments = $estQuery->get()->map(function ($row) {
                return [
                    'id' => (int) $row->id,
                    'description' => (string) $row->description,
                ];
            })->values()->all();
        }

        $cashes = CashSelector::options($isAdmin, $userEstId, $userId, CashSelector::filtersFrom($request ? $request->all() : null));

        return response()->json([
            'establishments' => $establishments,
            'cashes' => $cashes,
            'default_denominations' => CashCountService::defaultDenominations(),
            'is_admin' => $isAdmin,
            'default_establishment_id' => !$isAdmin && $userEstId > 0 ? $userEstId : null,
        ]);
    }

    public function records(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'cash_id' => ['nullable', 'integer', 'min:1'],
            'establishment_id' => ['nullable', 'integer', 'min:1'],
            'date_start' => ['nullable', 'date_format:Y-m-d'],
            'date_end' => ['nullable', 'date_format:Y-m-d'],
            'status' => ['nullable', 'in:balanced,surplus,shortage'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->service->listCounts($validated, $this->scope(auth()->user())),
        ]);
    }

    public function systemBalance(int $cash): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->service->getSystemBalance($cash, $this->scope(auth()->user())),
        ]);
    }

    public function store(StoreCashCountRequest $request): JsonResponse
    {
        $user = auth()->user();
        $scope = $this->scope($user);
        $count = $this->service->create($request->validated(), (int) ($user->id ?? 0), $scope);
        $formatted = $this->service->formatCountRow($count);

        return response()->json([
            'success' => true,
            'message' => 'Arqueo de caja registrado correctamente (' . $formatted['status_label'] . ').',
            'data' => $formatted,
        ], 201);
    }

    public function pdf(int $id)
    {
        $count = $this->service->findForUser($id, $this->scope(auth()->user()));
        $record = $this->service->formatCountRow($count);
        $company = $this->companyMeta();

        $pdf = PDF::loadView('treasury::cash_counts.report_pdf', [
            'record' => $record,
            'company' => $company,
        ])->setPaper('a4', 'portrait');

        $paddedId = str_pad((string) $record['id'], 6, '0', STR_PAD_LEFT);

        return $pdf->download('Arqueo_Caja_' . $paddedId . '_' . date('Ymd') . '.pdf');
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
            // Fall back to empty metadata.
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
