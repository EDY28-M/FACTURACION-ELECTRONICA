<?php

namespace Modules\Treasury\Http\Controllers;

use App\Models\Tenant\Catalogs\CurrencyType;
use App\Models\Tenant\Establishment;
use App\Models\Tenant\Purchase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Treasury\Http\Requests\StorePurchaseFeesRequest;
use Modules\Treasury\Services\PurchaseFeeService;
use Modules\Treasury\Services\ReceivablePayableService;

class ReceivablePayableController extends Controller
{
    private $receivablePayableService;
    private $purchaseFeeService;

    public function __construct(
        ReceivablePayableService $receivablePayableService,
        PurchaseFeeService $purchaseFeeService
    ) {
        $this->receivablePayableService = $receivablePayableService;
        $this->purchaseFeeService = $purchaseFeeService;
    }

    public function index()
    {
        return view('treasury::receivables_payables.index');
    }

    public function tables(): JsonResponse
    {
        $user = auth()->user();
        $isAdmin = $this->isAdmin($user);
        $userEstId = $user && !empty($user->establishment_id) ? (int) $user->establishment_id : null;

        $establishmentsQuery = Establishment::query()->orderBy('id');
        if (!$isAdmin) {
            $establishmentsQuery->where('id', $userEstId ?? 0);
        }
        $establishments = $establishmentsQuery->get()->map(function ($row) {
            return [
                'id' => (int) $row->id,
                'description' => $row->description,
            ];
        })->values();

        $currencyTypes = CurrencyType::whereActive()->get(['id', 'description', 'symbol']);

        return response()->json([
            'establishments' => $establishments,
            'currency_types' => $currencyTypes,
            'default_establishment_id' => !$isAdmin ? $userEstId : null,
            'is_admin' => $isAdmin,
        ]);
    }

    public function records(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'establishment_id' => ['nullable', 'integer', 'min:1'],
            'currency_type_id' => ['nullable', 'string', 'max:3'],
            'customer_id' => ['nullable', 'integer', 'min:1'],
            'supplier_id' => ['nullable', 'integer', 'min:1'],
            'date_start' => ['nullable', 'date_format:Y-m-d'],
            'date_end' => ['nullable', 'date_format:Y-m-d'],
            'status' => ['nullable', 'in:all,overdue,due_soon,pending'],
            'search' => ['nullable', 'string', 'max:120'],
            'as_of_date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $user = auth()->user();
        $isAdmin = $this->isAdmin($user);

        $filters = [
            'establishment_id' => !$isAdmin
                ? ($user && !empty($user->establishment_id) ? (int) $user->establishment_id : 0)
                : (!empty($validated['establishment_id']) ? (int) $validated['establishment_id'] : null),
            'user_id' => !$isAdmin
                ? ($user ? (int) $user->id : null)
                : null,
            'is_admin' => $isAdmin,
            'currency_type_id' => $validated['currency_type_id'] ?? null,
            'customer_id' => $validated['customer_id'] ?? null,
            'supplier_id' => $validated['supplier_id'] ?? null,
            'date_start' => $validated['date_start'] ?? null,
            'date_end' => $validated['date_end'] ?? null,
            'status' => $validated['status'] ?? 'all',
            'search' => $validated['search'] ?? null,
        ];

        $overview = $this->receivablePayableService->getOverview(
            $filters,
            $validated['as_of_date'] ?? null
        );

        return response()->json([
            'success' => true,
            'data' => $overview,
        ]);
    }

    public function storePurchaseFees(StorePurchaseFeesRequest $request, int $purchaseId): JsonResponse
    {
        $user = auth()->user();
        if ($denied = $this->authorizePurchaseWrite($user, $purchaseId)) {
            return $denied;
        }

        $validated = $request->validated();
        $fees = $this->purchaseFeeService->replaceFees($purchaseId, $validated['fees']);

        return response()->json([
            'success' => true,
            'message' => 'Cronograma de cuotas de la compra guardado con éxito.',
            'data' => $fees,
        ]);
    }

    public function deletePurchaseFees(int $purchaseId): JsonResponse
    {
        $user = auth()->user();
        if ($denied = $this->authorizePurchaseWrite($user, $purchaseId)) {
            return $denied;
        }

        $this->purchaseFeeService->deleteFees($purchaseId);

        return response()->json([
            'success' => true,
            'message' => 'Cronograma de cuotas de la compra eliminado con éxito.',
        ]);
    }

    private function authorizePurchaseWrite($user, int $purchaseId): ?JsonResponse
    {
        if ($this->isAdmin($user)) {
            return null;
        }

        $purchase = Purchase::query()->setEagerLoads([])->findOrFail($purchaseId);
        $userId = $user ? (int) $user->id : 0;
        $userEstId = $user && !empty($user->establishment_id) ? (int) $user->establishment_id : 0;

        if ((int) $purchase->user_id !== $userId || (int) $purchase->establishment_id !== $userEstId) {
            return response()->json([
                'success' => false,
                'message' => 'No tiene permiso para gestionar cuotas de esta compra.',
            ], 403);
        }

        return null;
    }

    private function isAdmin($user): bool
    {
        return $user !== null && ($user->type ?? null) === 'admin';
    }
}
