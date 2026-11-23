<?php

namespace Modules\Treasury\Http\Controllers;

use App\Models\Tenant\BankAccount;
use App\Models\Tenant\Catalogs\CurrencyType;
use App\Models\Tenant\Establishment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Modules\Treasury\Http\Requests\DepositDetractionRequest;
use Modules\Treasury\Http\Requests\ReleaseDetractionRequest;
use Modules\Treasury\Models\DetractionRecord;
use Modules\Treasury\Services\DetractionService;

class DetractionController extends Controller
{
    private $detractionService;

    public function __construct(DetractionService $detractionService)
    {
        $this->detractionService = $detractionService;
    }

    public function index()
    {
        return view('treasury::detractions.index');
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
            return ['id' => (int) $row->id, 'description' => $row->description];
        })->values();

        $accountsQuery = BankAccount::with('bank')->where('status', 1)->where('currency_type_id', 'PEN')->orderBy('id');
        if (!$isAdmin) {
            $accountsQuery->where(function ($q) use ($userEstId) {
                $q->whereNull('establishment_id')->orWhere('establishment_id', $userEstId ?? 0);
            });
        }
        $accounts = $accountsQuery->get()->map(function ($row) {
            $bank = $row->bank ? $row->bank->description : 'Banco';

            return [
                'id' => (int) $row->id,
                'description' => "{$bank} - {$row->currency_type_id} - {$row->description}",
                'number' => $row->number,
                'account_type' => $row->account_type ?: 'checking',
                'is_detraction_bn' => ($row->account_type ?? null) === 'detraction_bn',
            ];
        })->values();

        return response()->json([
            'establishments' => $establishments,
            'currency_types' => CurrencyType::whereActive()->get(['id', 'description', 'symbol']),
            'bn_accounts' => $accounts->where('is_detraction_bn', true)->values(),
            'own_accounts' => $accounts->where('is_detraction_bn', false)->values(),
            'default_establishment_id' => !$isAdmin ? $userEstId : null,
            'is_admin' => $isAdmin,
            'release_info' => (array) config('treasury.detraction_release_info', []),
        ]);
    }

    public function records(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'side' => ['required', 'in:receivable,payable'],
            'status' => ['nullable', 'in:all,pending,overdue,deposited,received'],
            'establishment_id' => ['nullable', 'integer', 'min:1'],
            'date_start' => ['nullable', 'date_format:Y-m-d'],
            'date_end' => ['nullable', 'date_format:Y-m-d'],
            'search' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $this->safeSync();

        $filters = array_merge($this->scopeFilters(auth()->user(), $validated['establishment_id'] ?? null), [
            'status' => $validated['status'] ?? 'all',
            'date_start' => $validated['date_start'] ?? null,
            'date_end' => $validated['date_end'] ?? null,
            'search' => $validated['search'] ?? null,
            'page' => $validated['page'] ?? 1,
            'per_page' => $validated['per_page'] ?? 20,
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->detractionService->listRecords($validated['side'], $filters),
        ]);
    }

    public function sync(): JsonResponse
    {
        $result = $this->detractionService->syncFromSources(500);

        return response()->json([
            'success' => true,
            'message' => 'Detracciones sincronizadas: ' . $result['created'] . ' nuevas.',
            'data' => $result,
        ]);
    }

    public function deposit(DepositDetractionRequest $request, int $id): JsonResponse
    {
        $user = auth()->user();
        $payload = $request->validated();

        if ($denied = $this->authorizeAccount($user, $payload['bank_account_id'] ?? null)) {
            return $denied;
        }

        $record = $this->detractionService->registerDeposit($id, $payload, (int) $user->id, $this->scope($user));

        return response()->json([
            'success' => true,
            'message' => $record->side === DetractionRecord::SIDE_RECEIVABLE
                ? 'Depósito de detracción recibido y registrado en la cuenta del Banco de la Nación.'
                : 'Depósito de detracción registrado correctamente.',
            'data' => [
                'id' => (int) $record->id,
                'status' => $record->status,
                'deposit_movement_id' => $record->deposit_movement_id,
            ],
        ]);
    }

    public function revert(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'void_reason' => ['required', 'string', 'min:3', 'max:500'],
        ], [
            'void_reason.required' => 'El motivo de la reversión es obligatorio.',
            'void_reason.min' => 'El motivo de la reversión debe tener al menos 3 caracteres.',
        ]);

        $user = auth()->user();
        $record = $this->detractionService->revertDeposit($id, $validated['void_reason'], (int) $user->id, $this->scope($user));

        return response()->json([
            'success' => true,
            'message' => 'Depósito revertido. La detracción vuelve a estar pendiente.',
            'data' => ['id' => (int) $record->id, 'status' => $record->status],
        ]);
    }

    /**
     * Release of SPOT funds (Formulario 1697). Moves company funds between accounts, so admin only.
     */
    public function release(ReleaseDetractionRequest $request): JsonResponse
    {
        $user = auth()->user();
        if (!$this->isAdmin($user)) {
            return response()->json([
                'success' => false,
                'message' => 'Solo un administrador puede registrar la liberación de fondos de detracciones.',
            ], 403);
        }

        $payload = $request->validated();
        if (empty($payload['establishment_id'])) {
            $payload['establishment_id'] = $user && $user->establishment_id
                ? (int) $user->establishment_id
                : (int) (Establishment::orderBy('id')->value('id') ?? 0);
        }

        $result = $this->detractionService->registerRelease($payload, (int) $user->id);

        return response()->json([
            'success' => true,
            'message' => 'Liberación de fondos registrada correctamente.',
            'data' => [
                'transfer_group' => $result['transfer_group'],
                'out_movement_id' => (int) $result['out']->id,
                'in_movement_id' => (int) $result['in']->id,
            ],
        ], 201);
    }

    private function safeSync(): void
    {
        try {
            $this->detractionService->syncFromSources(300);
        } catch (\Throwable $e) {
            Log::warning('Treasury detraction sync failed', ['error' => $e->getMessage()]);
        }
    }

    private function scope($user): array
    {
        return [
            'is_admin' => $this->isAdmin($user),
            'user_id' => $user ? (int) $user->id : null,
            'establishment_id' => $user && !empty($user->establishment_id) ? (int) $user->establishment_id : null,
        ];
    }

    private function scopeFilters($user, $requestedEstablishmentId): array
    {
        $isAdmin = $this->isAdmin($user);

        return [
            'is_admin' => $isAdmin,
            'user_id' => !$isAdmin ? ($user ? (int) $user->id : null) : null,
            'establishment_id' => !$isAdmin
                ? ($user && !empty($user->establishment_id) ? (int) $user->establishment_id : 0)
                : (!empty($requestedEstablishmentId) ? (int) $requestedEstablishmentId : null),
        ];
    }

    private function authorizeAccount($user, $accountId): ?JsonResponse
    {
        if ($accountId === null || $this->isAdmin($user)) {
            return null;
        }

        $account = BankAccount::find((int) $accountId);
        $userEstId = (int) ($user->establishment_id ?? 0);
        if ($account && !empty($account->establishment_id) && (int) $account->establishment_id !== $userEstId) {
            return response()->json([
                'success' => false,
                'message' => 'No tiene permiso para operar sobre una cuenta bancaria de otra sede.',
            ], 403);
        }

        return null;
    }

    private function isAdmin($user): bool
    {
        return $user !== null && ($user->type ?? null) === 'admin';
    }
}
