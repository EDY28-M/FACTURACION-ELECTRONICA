<?php

namespace Modules\Treasury\Http\Controllers;

use App\Models\Tenant\BankAccount;
use App\Models\Tenant\Cash;
use App\Models\Tenant\Catalogs\CurrencyType;
use App\Models\Tenant\Establishment;
use App\Models\Tenant\PaymentMethodType;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Treasury\Http\Requests\StoreMovementRequest;
use Modules\Treasury\Http\Requests\VoidMovementRequest;
use Modules\Treasury\Models\TreasuryConcept;
use Modules\Treasury\Models\TreasuryMovement;
use Modules\Treasury\Services\BancarizationChecker;
use Modules\Treasury\Services\MovementService;
use Modules\Treasury\Support\Money;

class MovementController extends Controller
{
    private $movementService;

    public function __construct(MovementService $movementService)
    {
        $this->movementService = $movementService;
    }

    public function index()
    {
        return view('treasury::movements.index');
    }

    public function tables()
    {
        $user = auth()->user();
        $isAdmin = $this->isAdmin($user);
        $userEstId = $user && !empty($user->establishment_id) ? (int) $user->establishment_id : null;

        $establishmentsQuery = Establishment::query();
        if (!$isAdmin && $userEstId !== null) {
            $establishmentsQuery->where('id', $userEstId);
        }
        $establishments = $establishmentsQuery->get()->map(function ($row) {
            return [
                'id' => (int) $row->id,
                'description' => $row->description,
            ];
        })->values();

        $bankAccountsQuery = BankAccount::with('bank')
            ->where('status', 1)
            ->orderBy('id');
        if (!$isAdmin && $userEstId !== null) {
            $bankAccountsQuery->where(function ($q) use ($userEstId) {
                $q->whereNull('establishment_id')->orWhere('establishment_id', $userEstId);
            });
        }
        $bank_accounts = $bankAccountsQuery
            ->get()
            ->map(function ($row) {
                $bankName = $row->bank ? $row->bank->description : 'Banco';

                return [
                    'id' => (int) $row->id,
                    'description' => "{$bankName} - {$row->currency_type_id} - {$row->description}",
                    'number' => $row->number,
                    'currency_type_id' => $row->currency_type_id,
                    'itf_exempt' => (bool) $row->itf_exempt,
                    'establishment_id' => $row->establishment_id ? (int) $row->establishment_id : null,
                ];
            })
            ->values();

        $openCashesQuery = Cash::with('user')
            ->where('state', true)
            ->orderBy('date_opening', 'desc')
            ->orderBy('id', 'desc');
        if (!$isAdmin && $user) {
            $openCashesQuery->where('user_id', (int) $user->id);
        }
        $open_cashes = $openCashesQuery
            ->get()
            ->map(function ($row) {
                $userName = $row->user ? $row->user->name : ('Usuario #' . $row->user_id);
                $ref = $row->reference_number ? " ({$row->reference_number})" : '';

                return [
                    'id' => (int) $row->id,
                    'description' => "Caja POS{$ref} - {$userName}",
                    'currency_type_id' => 'PEN',
                    'establishment_id' => ($row->user && $row->user->establishment_id) ? (int) $row->user->establishment_id : null,
                ];
            })
            ->values();

        $concepts = TreasuryConcept::active()
            ->orderBy('type')
            ->orderBy('description')
            ->get(['id', 'type', 'description', 'pcge_account']);

        $payment_method_types = PaymentMethodType::orderBy('id')
            ->get(['id', 'description', 'sunat_code', 'is_cash']);

        $currency_types = CurrencyType::whereActive()->get(['id', 'description', 'symbol']);

        $manual_kinds = collect(TreasuryMovement::MANUAL_KINDS)->map(function ($kind) {
            return [
                'value' => $kind,
                'label' => MovementService::KIND_LABELS[$kind] ?? $kind,
            ];
        })->values();

        $all_kinds = collect(TreasuryMovement::ALL_KINDS)->map(function ($kind) {
            return [
                'value' => $kind,
                'label' => MovementService::KIND_LABELS[$kind] ?? $kind,
            ];
        })->values();

        $sunat_payment_codes = BancarizationChecker::sunatPaymentCodeOptions();
        $bancarization_exceptions = BancarizationChecker::exceptionOptions();

        $default_establishment_id = $userEstId ?: ($establishments->first()['id'] ?? null);

        return response()->json([
            'establishments' => $establishments,
            'bank_accounts' => $bank_accounts,
            'open_cashes' => $open_cashes,
            'concepts' => $concepts,
            'payment_method_types' => $payment_method_types,
            'currency_types' => $currency_types,
            'manual_kinds' => $manual_kinds,
            'all_kinds' => $all_kinds,
            'sunat_payment_codes' => $sunat_payment_codes,
            'bancarization_exceptions' => $bancarization_exceptions,
            'default_establishment_id' => $default_establishment_id,
            'allow_future_dates' => (bool) config('treasury.allow_future_dates', false),
        ]);
    }

    public function records(Request $request)
    {
        $validated = $request->validate([
            'establishment_id' => ['nullable', 'integer'],
            'destination_type' => ['nullable', 'in:bank_account,cash'],
            'destination_id' => ['nullable', 'integer'],
            'currency_type_id' => ['nullable', 'string', 'max:3'],
            'kind' => ['nullable', 'string', 'max:30'],
            'state' => ['nullable', 'in:active,voided'],
            'date_start' => ['nullable', 'date_format:Y-m-d'],
            'date_end' => ['nullable', 'date_format:Y-m-d'],
            'search' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $user = auth()->user();
        $isAdmin = $this->isAdmin($user);

        $query = TreasuryMovement::with([
            'establishment',
            'concept',
            'payment_method_type',
            'user',
            'voided_by_user',
            'evidence',
            'destination' => function (MorphTo $morphTo) {
                $morphTo->morphWith([BankAccount::class => ['bank']]);
            },
        ])->orderBy('date', 'desc')->orderBy('id', 'desc');

        if (!$isAdmin && $user) {
            $query->where('establishment_id', (int) ($user->establishment_id ?? 0));
            $ownedCashIds = Cash::where('user_id', (int) $user->id)->pluck('id')->all();
            $query->where(function ($q) use ($ownedCashIds) {
                $q->where('destination_type', '!=', Cash::class);
                if (!empty($ownedCashIds)) {
                    $q->orWhereIn('destination_id', $ownedCashIds);
                }
            });
        } elseif (!empty($validated['establishment_id'])) {
            $query->where('establishment_id', (int) $validated['establishment_id']);
        }

        if (!empty($validated['destination_type'])) {
            $destClass = MovementService::DESTINATION_TYPES[$validated['destination_type']] ?? null;
            if ($destClass) {
                $query->where('destination_type', $destClass);
            }
        }
        if (!empty($validated['destination_id'])) {
            $query->where('destination_id', (int) $validated['destination_id']);
        }
        if (!empty($validated['currency_type_id'])) {
            $query->where('currency_type_id', $validated['currency_type_id']);
        }
        if (!empty($validated['kind'])) {
            $query->where('kind', $validated['kind']);
        }
        if (!empty($validated['state'])) {
            $query->where('state', $validated['state']);
        }
        if (!empty($validated['date_start'])) {
            $query->where('date', '>=', $validated['date_start']);
        }
        if (!empty($validated['date_end'])) {
            $query->where('date', '<=', $validated['date_end']);
        }
        if (!empty($validated['search'])) {
            $term = '%' . trim($validated['search']) . '%';
            $query->where(function ($q) use ($term) {
                $q->where('description', 'like', $term)
                    ->orWhere('operation_number', 'like', $term)
                    ->orWhere('counterparty_name', 'like', $term)
                    ->orWhere('counterparty_document', 'like', $term);
            });
        }

        $perPage = (int) ($validated['per_page'] ?? config('tenant.items_per_page', 20));
        $paginator = $query->paginate($perPage);

        $rows = $paginator->getCollection()->map(function (TreasuryMovement $movement) {
            return $this->transformMovement($movement);
        })->values();

        return response()->json([
            'success' => true,
            'data' => $rows,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function store(StoreMovementRequest $request)
    {
        $user = auth()->user();
        $payload = $request->validated();

        if ($denied = $this->authorizeDestinationWrite($user, $payload)) {
            return $denied;
        }

        if (!$this->isAdmin($user)) {
            $payload['establishment_id'] = (int) ($user->establishment_id ?? 0);
        } elseif (empty($payload['establishment_id'])) {
            $payload['establishment_id'] = $user && $user->establishment_id
                ? (int) $user->establishment_id
                : (int) (Establishment::orderBy('id')->value('id') ?? 0);
        }

        $result = $this->movementService->createManual($payload, (int) $user->id);

        $movement = $result['movement']->load([
            'establishment',
            'concept',
            'evidence',
            'destination' => function (MorphTo $morphTo) {
                $morphTo->morphWith([BankAccount::class => ['bank']]);
            },
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Movimiento de tesorería registrado correctamente.',
            'data' => [
                'movement' => $this->transformMovement($movement),
                'related_count' => count($result['related_movements']),
                'bancarization' => $result['bancarization'],
            ],
        ], 201);
    }

    public function void(VoidMovementRequest $request, $id)
    {
        $user = auth()->user();
        $target = TreasuryMovement::findOrFail((int) $id);

        if ($denied = $this->authorizeVoid($user, $target)) {
            return $denied;
        }

        $result = $this->movementService->void(
            (int) $id,
            (string) $request->input('void_reason'),
            (int) $user->id
        );

        return response()->json([
            'success' => true,
            'message' => $result['transfer_group']
                ? 'Transferencia y movimientos asociados anulados correctamente.'
                : 'Movimiento anulado correctamente.',
            'data' => [
                'voided_count' => $result['count'],
                'transfer_group' => $result['transfer_group'],
            ],
        ]);
    }

    public function preview(Request $request)
    {
        $validated = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'destination_type' => ['nullable', 'in:bank_account,cash'],
            'destination_id' => ['nullable', 'integer'],
            'currency_type_id' => ['nullable', 'string', 'max:3'],
            'payment_method_type_id' => ['nullable', 'string', 'max:2'],
            'sunat_payment_code' => ['nullable', 'string', 'max:3'],
            'same_holder' => ['nullable', 'boolean'],
            'low_compliance_counterparty' => ['nullable', 'boolean'],
            'exchange_rate_sale' => ['nullable', 'numeric', 'min:0.0001'],
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->movementService->preview($validated),
        ]);
    }

    public function exchangeRate($date)
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date)) {
            return response()->json([
                'success' => false,
                'message' => 'Formato de fecha inválido (use YYYY-MM-DD).',
            ], 422);
        }

        $rate = $this->movementService->lookupExchangeRateOnOrBefore((string) $date);

        if (!$rate) {
            return response()->json([
                'success' => true,
                'data' => [
                    'date' => (string) $date,
                    'purchase' => null,
                    'sale' => null,
                    'source' => 'missing',
                ],
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'date' => (string) $rate->date,
                'purchase' => (float) $rate->purchase,
                'sale' => (float) $rate->sale,
                'source' => ((string) $rate->date === (string) $date) ? 'exact' : 'latest_prior',
            ],
        ]);
    }

    private function isAdmin($user): bool
    {
        return $user !== null && ($user->type ?? null) === 'admin';
    }

    private function authorizeDestinationWrite($user, array $payload): ?JsonResponse
    {
        if ($this->isAdmin($user)) {
            return null;
        }

        $userEstId = (int) ($user->establishment_id ?? 0);
        if (!empty($payload['establishment_id']) && (int) $payload['establishment_id'] !== $userEstId) {
            return $this->forbiddenResponse('No tiene permiso para registrar movimientos en otra sede.');
        }

        $destType = (string) ($payload['destination_type'] ?? '');
        $destId = (int) ($payload['destination_id'] ?? 0);

        if ($destType === 'cash' && $destId > 0) {
            $cash = Cash::find($destId);
            if ($cash && (int) $cash->user_id !== (int) $user->id) {
                return $this->forbiddenResponse('No tiene permiso para operar sobre la caja POS de otro usuario.');
            }
        }

        if ($destType === 'bank_account' && $destId > 0) {
            $account = BankAccount::find($destId);
            if ($account && !empty($account->establishment_id) && (int) $account->establishment_id !== $userEstId) {
                return $this->forbiddenResponse('No tiene permiso para operar sobre una cuenta bancaria de otra sede.');
            }
        }

        return null;
    }

    private function authorizeVoid($user, TreasuryMovement $target): ?JsonResponse
    {
        if ($this->isAdmin($user)) {
            return null;
        }

        $userId = (int) ($user->id ?? 0);
        $userEstId = (int) ($user->establishment_id ?? 0);

        $legs = $target->transfer_group !== null
            ? TreasuryMovement::where('transfer_group', $target->transfer_group)->get()
            : collect([$target]);

        foreach ($legs as $leg) {
            if ((int) $leg->establishment_id !== $userEstId || (int) $leg->user_id !== $userId) {
                return $this->forbiddenResponse('No tiene permiso para anular este movimiento.');
            }
            if ($leg->destination_type === Cash::class) {
                $cash = Cash::find($leg->destination_id);
                if (!$cash || (int) $cash->user_id !== $userId) {
                    return $this->forbiddenResponse('No tiene permiso para anular movimientos de la caja POS de otro usuario.');
                }
            }
        }

        return null;
    }

    private function forbiddenResponse(string $message): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], 403);
    }

    private function transformMovement(TreasuryMovement $movement): array
    {
        $destinationLabel = '-';
        $destinationAlias = $movement->destination_type === BankAccount::class ? 'bank_account' : 'cash';

        if ($movement->destination instanceof BankAccount) {
            $bank = $movement->destination->bank;
            $bankName = $bank ? $bank->description : 'Banco';
            $destinationLabel = "{$bankName} - {$movement->destination->currency_type_id} - {$movement->destination->description}";
        } elseif ($movement->destination instanceof Cash) {
            $ref = $movement->destination->reference_number ? " ({$movement->destination->reference_number})" : '';
            $destinationLabel = "Caja POS{$ref}";
        }

        return [
            'id' => (int) $movement->id,
            'date' => $movement->date ? $movement->date->format('Y-m-d') : null,
            'kind' => $movement->kind,
            'kind_label' => MovementService::KIND_LABELS[$movement->kind] ?? $movement->kind,
            'direction' => (int) $movement->direction,
            'destination_type' => $destinationAlias,
            'destination_id' => (int) $movement->destination_id,
            'destination_label' => $destinationLabel,
            'establishment_id' => (int) $movement->establishment_id,
            'establishment_description' => $movement->establishment ? $movement->establishment->description : '-',
            'currency_type_id' => $movement->currency_type_id,
            'amount' => Money::format((float) $movement->amount),
            'signed_amount' => Money::format((float) $movement->amount * (int) $movement->direction),
            'amount_pen' => Money::format((float) $movement->amount_pen),
            'exchange_rate_used' => $movement->exchange_rate_used ? (float) $movement->exchange_rate_used : null,
            'concept_description' => $movement->concept ? $movement->concept->description : '-',
            'payment_method_description' => $movement->payment_method_type ? $movement->payment_method_type->description : '-',
            'sunat_payment_code' => $movement->sunat_payment_code,
            'operation_number' => $movement->operation_number,
            'counterparty_document' => $movement->counterparty_document,
            'counterparty_name' => $movement->counterparty_name,
            'description' => $movement->description,
            'transfer_group' => $movement->transfer_group,
            'related_movement_id' => $movement->related_movement_id ? (int) $movement->related_movement_id : null,
            'state' => $movement->state,
            'voided_at' => $movement->voided_at ? $movement->voided_at->format('Y-m-d H:i') : null,
            'voided_by_name' => $movement->voided_by_user ? $movement->voided_by_user->name : null,
            'void_reason' => $movement->void_reason,
            'user_name' => $movement->user ? $movement->user->name : '-',
            'has_evidence' => $movement->evidence !== null,
        ];
    }
}
