<?php

namespace Modules\Treasury\Http\Controllers;

use App\Models\Tenant\BankAccount;
use App\Models\Tenant\Cash;
use App\Models\Tenant\Establishment;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Treasury\Http\Requests\StoreTransferRequest;
use Modules\Treasury\Http\Requests\VoidMovementRequest;
use Modules\Treasury\Models\TreasuryMovement;
use Modules\Treasury\Services\BancarizationChecker;
use Modules\Treasury\Services\MovementService;
use Modules\Treasury\Services\TransferService;
use Modules\Treasury\Support\Money;

class TransferController extends Controller
{
    private $transferService;
    private $movementService;

    public function __construct(
        TransferService $transferService,
        MovementService $movementService
    ) {
        $this->transferService = $transferService;
        $this->movementService = $movementService;
    }

    public function index()
    {
        return view('treasury::transfers.index');
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

        $sunat_payment_codes = BancarizationChecker::sunatPaymentCodeOptions();
        $default_establishment_id = $userEstId ?: ($establishments->first()['id'] ?? null);

        return response()->json([
            'establishments' => $establishments,
            'bank_accounts' => $bank_accounts,
            'open_cashes' => $open_cashes,
            'sunat_payment_codes' => $sunat_payment_codes,
            'default_establishment_id' => $default_establishment_id,
            'allow_future_dates' => (bool) config('treasury.allow_future_dates', false),
        ]);
    }

    public function records(Request $request)
    {
        $validated = $request->validate([
            'establishment_id' => ['nullable', 'integer'],
            'state' => ['nullable', 'in:active,voided'],
            'date_start' => ['nullable', 'date_format:Y-m-d'],
            'date_end' => ['nullable', 'date_format:Y-m-d'],
            'search' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $user = auth()->user();
        $isAdmin = $this->isAdmin($user);

        // List transfer_out legs as the canonical row per transfer group, eager-loading paired leg and child ITF/fees.
        $query = TreasuryMovement::with([
            'establishment',
            'user',
            'voided_by_user',
            'evidence',
            'destination' => function (MorphTo $morphTo) {
                $morphTo->morphWith([BankAccount::class => ['bank']]);
            },
            'child_movements',
        ])
            ->where('kind', 'transfer_out')
            ->whereNotNull('transfer_group')
            ->orderBy('date', 'desc')
            ->orderBy('id', 'desc');

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
                    ->orWhere('operation_number', 'like', $term);
            });
        }

        $perPage = (int) ($validated['per_page'] ?? config('tenant.items_per_page', 20));
        $paginator = $query->paginate($perPage);

        $groups = $paginator->getCollection()->pluck('transfer_group')->filter()->values()->all();
        $inLegsByGroup = empty($groups)
            ? collect()
            : TreasuryMovement::with([
                'destination' => function (MorphTo $morphTo) {
                    $morphTo->morphWith([BankAccount::class => ['bank']]);
                },
            ])
                ->where('kind', 'transfer_in')
                ->whereIn('transfer_group', $groups)
                ->get()
                ->keyBy('transfer_group');

        $rows = $paginator->getCollection()->map(function (TreasuryMovement $out) use ($inLegsByGroup) {
            /** @var TreasuryMovement|null $in */
            $in = $inLegsByGroup->get($out->transfer_group);

            $itfMovement = $out->child_movements->firstWhere('kind', 'itf');
            $feeMovement = $out->child_movements->firstWhere('kind', 'bank_fee');

            return [
                'id' => (int) $out->id,
                'transfer_group' => $out->transfer_group,
                'date' => $out->date ? $out->date->format('Y-m-d') : null,
                'origin_label' => $this->formatDestinationLabel($out),
                'origin_currency' => $out->currency_type_id,
                'origin_amount' => Money::format((float) $out->amount),
                'target_label' => $in ? $this->formatDestinationLabel($in) : '-',
                'target_currency' => $in ? $in->currency_type_id : $out->currency_type_id,
                'target_amount' => $in ? Money::format((float) $in->amount) : Money::format((float) $out->amount),
                'exchange_rate_used' => $out->exchange_rate_used ? (float) $out->exchange_rate_used : null,
                'is_cross_currency' => $in ? ($out->currency_type_id !== $in->currency_type_id) : false,
                'itf_amount' => $itfMovement ? Money::format((float) $itfMovement->amount) : null,
                'itf_currency' => $itfMovement ? $itfMovement->currency_type_id : null,
                'bank_fee_amount' => $feeMovement ? Money::format((float) $feeMovement->amount) : null,
                'bank_fee_currency' => $feeMovement ? $feeMovement->currency_type_id : null,
                'operation_number' => $out->operation_number,
                'description' => $out->description,
                'establishment_description' => $out->establishment ? $out->establishment->description : '-',
                'state' => $out->state,
                'voided_at' => $out->voided_at ? $out->voided_at->format('Y-m-d H:i') : null,
                'voided_by_name' => $out->voided_by_user ? $out->voided_by_user->name : null,
                'void_reason' => $out->void_reason,
                'user_name' => $out->user ? $out->user->name : '-',
                'has_evidence' => $out->evidence !== null,
            ];
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

    public function store(StoreTransferRequest $request)
    {
        $user = auth()->user();
        $payload = $request->validated();

        if ($denied = $this->authorizeTransferWrite($user, $payload)) {
            return $denied;
        }

        if (!$this->isAdmin($user)) {
            $payload['establishment_id'] = (int) ($user->establishment_id ?? 0);
        } elseif (empty($payload['establishment_id'])) {
            $payload['establishment_id'] = $user && $user->establishment_id
                ? (int) $user->establishment_id
                : (int) (Establishment::orderBy('id')->value('id') ?? 0);
        }

        $result = $this->transferService->create($payload, (int) $user->id);

        return response()->json([
            'success' => true,
            'message' => 'Transferencia registrada correctamente.',
            'data' => [
                'transfer_group' => $result['transfer_group'],
                'out_id' => (int) $result['out']->id,
                'in_id' => (int) $result['in']->id,
                'related_count' => count($result['related_movements']),
            ],
        ], 201);
    }

    public function void(VoidMovementRequest $request, $id)
    {
        $user = auth()->user();
        $target = TreasuryMovement::findOrFail((int) $id);

        if ($target->transfer_group === null) {
            return response()->json([
                'success' => false,
                'message' => 'El movimiento indicado no corresponde a una transferencia.',
                'errors' => [
                    'movement' => ['El movimiento indicado no corresponde a una transferencia.'],
                ],
            ], 422);
        }

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
            'message' => 'Transferencia y cargos asociados anulados correctamente.',
            'data' => [
                'voided_count' => $result['count'],
                'transfer_group' => $result['transfer_group'],
            ],
        ]);
    }

    private function isAdmin($user): bool
    {
        return $user !== null && ($user->type ?? null) === 'admin';
    }

    private function authorizeTransferWrite($user, array $payload): ?JsonResponse
    {
        if ($this->isAdmin($user)) {
            return null;
        }

        $userEstId = (int) ($user->establishment_id ?? 0);
        if (!empty($payload['establishment_id']) && (int) $payload['establishment_id'] !== $userEstId) {
            return $this->forbiddenResponse('No tiene permiso para registrar transferencias en otra sede.');
        }

        foreach ([['origin_type', 'origin_id'], ['target_type', 'target_id']] as [$typeKey, $idKey]) {
            $destType = (string) ($payload[$typeKey] ?? '');
            $destId = (int) ($payload[$idKey] ?? 0);

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

        $legs = TreasuryMovement::where('transfer_group', $target->transfer_group)->get();
        foreach ($legs as $leg) {
            if ((int) $leg->establishment_id !== $userEstId || (int) $leg->user_id !== $userId) {
                return $this->forbiddenResponse('No tiene permiso para anular esta transferencia.');
            }
            if ($leg->destination_type === Cash::class) {
                $cash = Cash::find($leg->destination_id);
                if (!$cash || (int) $cash->user_id !== $userId) {
                    return $this->forbiddenResponse('No tiene permiso para anular transferencias de la caja POS de otro usuario.');
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

    private function formatDestinationLabel(TreasuryMovement $movement): string
    {
        if ($movement->destination instanceof BankAccount) {
            $bank = $movement->destination->bank;
            $bankName = $bank ? $bank->description : 'Banco';

            return "{$bankName} - {$movement->destination->currency_type_id} - {$movement->destination->description}";
        }

        if ($movement->destination instanceof Cash) {
            $ref = $movement->destination->reference_number ? " ({$movement->destination->reference_number})" : '';

            return "Caja POS{$ref}";
        }

        return '-';
    }
}
