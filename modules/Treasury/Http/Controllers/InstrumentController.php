<?php

namespace Modules\Treasury\Http\Controllers;

use App\Models\Tenant\Bank;
use App\Models\Tenant\BankAccount;
use App\Models\Tenant\Catalogs\CurrencyType;
use App\Models\Tenant\Establishment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Treasury\Http\Requests\StoreInstrumentRequest;
use Modules\Treasury\Http\Requests\TransitionInstrumentRequest;
use Modules\Treasury\Models\TreasuryInstrument;
use Modules\Treasury\Services\InstrumentService;
use Modules\Treasury\Support\InstrumentDeadlines;

/**
 * Cheques y letras (Phase 7). Validates, authorizes and delegates; every rule lives in InstrumentService.
 * Non-admins only see and manage their own instruments, in their own establishment (deny establishment <= 0).
 */
class InstrumentController extends Controller
{
    private $service;
    private $deadlines;

    public function __construct(InstrumentService $service, InstrumentDeadlines $deadlines)
    {
        $this->service = $service;
        $this->deadlines = $deadlines;
    }

    public function index()
    {
        return view('treasury::instruments.index');
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

        $accountsQuery = BankAccount::with('bank')
            ->where('status', 1)
            ->where(function ($q) {
                $q->whereNull('account_type')->orWhere('account_type', '!=', 'detraction_bn');
            })
            ->orderBy('id');
        if (!$isAdmin) {
            $accountsQuery->where(function ($q) use ($userEstId) {
                $q->whereNull('establishment_id')->orWhere('establishment_id', $userEstId ?? 0);
            });
            if ($userEstId === null) {
                $accountsQuery->whereRaw('1 = 0');
            }
        }

        $today = date('Y-m-d');

        return response()->json([
            'establishments' => $establishmentsQuery->get()->map(function ($row) {
                return ['id' => (int) $row->id, 'description' => $row->description];
            })->values(),
            'banks' => Bank::query()->orderBy('description')->get(['id', 'description'])->map(function ($row) {
                return ['id' => (int) $row->id, 'description' => $row->description];
            })->values(),
            'accounts' => $accountsQuery->get()->map(function ($row) {
                $bank = $row->bank ? $row->bank->description : 'Banco';

                return [
                    'id' => (int) $row->id,
                    'description' => "{$bank} - {$row->currency_type_id} - {$row->description}",
                    'currency_type_id' => $row->currency_type_id,
                    'bank_id' => $row->bank_id ? (int) $row->bank_id : null,
                    'establishment_id' => $row->establishment_id ? (int) $row->establishment_id : null,
                ];
            })->values(),
            'currency_types' => CurrencyType::whereActive()->get(['id', 'description', 'symbol']),
            'types' => collect(TreasuryInstrument::TYPES)->map(function ($type) {
                return [
                    'value' => $type,
                    'label' => InstrumentService::TYPE_LABELS[$type],
                    'status_labels' => InstrumentService::STATUS_LABELS[$type],
                    'is_inflow' => in_array($type, [TreasuryInstrument::TYPE_CHEQUE_RECEIVED, TreasuryInstrument::TYPE_BILL_RECEIVABLE], true),
                ];
            })->values(),
            'rules' => [
                'cheque_presentation_days' => $this->deadlines->chequePresentationDays($today),
                'cheque_deferred_max_days' => $this->deadlines->chequeDeferredMaxDays($today),
                'bill_protest_days' => $this->deadlines->billProtestDays($today),
                'protest_day_type' => $this->deadlines->protestDayType(),
            ],
            'default_establishment_id' => !$isAdmin ? $userEstId : null,
            'is_admin' => $isAdmin,
        ]);
    }

    public function records(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'in:' . implode(',', TreasuryInstrument::TYPES)],
            'status' => ['nullable', 'in:all,open,' . implode(',', TreasuryInstrument::STATUSES)],
            'establishment_id' => ['nullable', 'integer', 'min:1'],
            'date_start' => ['nullable', 'date_format:Y-m-d'],
            'date_end' => ['nullable', 'date_format:Y-m-d'],
            'search' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

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
            'data' => $this->service->listRecords($validated['type'], $filters),
        ]);
    }

    public function persons(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'in:' . implode(',', TreasuryInstrument::TYPES)],
            'search' => ['nullable', 'string', 'max:120'],
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->service->lookupPersons($validated['type'], $validated['search'] ?? null),
        ]);
    }

    public function sources(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'in:' . implode(',', TreasuryInstrument::TYPES)],
            'search' => ['nullable', 'string', 'max:60'],
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->service->lookupSources($validated['type'], $validated['search'] ?? null, $this->scope(auth()->user())),
        ]);
    }

    public function history(int $id): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->service->history($id, $this->scope(auth()->user())),
        ]);
    }

    public function store(StoreInstrumentRequest $request): JsonResponse
    {
        $user = auth()->user();
        $instrument = $this->service->create($request->validated(), (int) $user->id, $this->scope($user));

        return response()->json([
            'success' => true,
            'message' => InstrumentService::TYPE_LABELS[$instrument->type] . ' registrado correctamente.',
            'data' => $this->service->present($instrument->load(['bank', 'bankAccount', 'person'])),
        ], 201);
    }

    public function update(StoreInstrumentRequest $request, int $id): JsonResponse
    {
        $user = auth()->user();
        $instrument = $this->service->update($id, $request->validated(), (int) $user->id, $this->scope($user));

        return response()->json([
            'success' => true,
            'message' => 'Datos actualizados correctamente.',
            'data' => $this->service->present($instrument->load(['bank', 'bankAccount', 'person'])),
        ]);
    }

    public function transition(TransitionInstrumentRequest $request, int $id): JsonResponse
    {
        $user = auth()->user();
        $payload = $request->validated();
        $result = $this->service->transition($id, $payload['to'], $payload, (int) $user->id, $this->scope($user));

        $instrument = $result['instrument']->load(['bank', 'bankAccount', 'person']);

        return response()->json([
            'success' => true,
            'message' => 'Estado actualizado: ' . InstrumentService::statusLabel($instrument->type, $instrument->status) . '.',
            'warnings' => $result['warnings'],
            'movement_id' => $result['movement'] ? (int) $result['movement']->id : null,
            'data' => $this->service->present($instrument),
        ]);
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
            'user_id' => $user ? (int) $user->id : null,
            'establishment_id' => !$isAdmin
                ? ($user && !empty($user->establishment_id) ? (int) $user->establishment_id : 0)
                : (!empty($requestedEstablishmentId) ? (int) $requestedEstablishmentId : null),
        ];
    }

    private function isAdmin($user): bool
    {
        return $user !== null && ($user->type ?? null) === 'admin';
    }
}
