<?php

namespace Modules\Treasury\Services;

use App\Models\Tenant\Cash;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Treasury\Models\TreasuryCashCount;
use Modules\Treasury\Support\Money;

/**
 * Manages Cash Counts / Arqueo de caja (Phase 6):
 * denomination counting, server-side system balance lookup via TreasuryBalanceService,
 * difference calculation, and strictly scoped history queries.
 *
 * Never calls auth() internally; callers must pass explicit $userId and $scope.
 */
class CashCountService
{
    /** Fallback used only when treasury.cash_count.denominations is missing. */
    private const FALLBACK_PEN_DENOMINATIONS = [200.00, 100.00, 50.00, 20.00, 10.00, 5.00, 2.00, 1.00, 0.50, 0.20, 0.10];

    private $balanceService;

    public function __construct(TreasuryBalanceService $balanceService)
    {
        $this->balanceService = $balanceService;
    }

    /**
     * Returns the default Peru PEN denomination template for the UI grid.
     */
    public static function defaultDenominations(): array
    {
        $items = [];
        $configured = config('treasury.cash_count.denominations');
        $values = is_array($configured) && count($configured) > 0 ? $configured : self::FALLBACK_PEN_DENOMINATIONS;
        foreach ($values as $value) {
            $value = (float) $value;
            $items[] = [
                'value' => $value,
                'type' => $value >= 10.0 ? 'bill' : 'coin',
                'label' => ($value >= 10.0 ? 'Billete' : 'Moneda') . ' S/ ' . number_format($value, 2, '.', ''),
                'qty' => 0,
                'subtotal' => 0.0,
            ];
        }

        return $items;
    }

    /**
     * Returns current system balance for one cash box after verifying access.
     */
    public function getSystemBalance(int $cashId, array $scope): array
    {
        $cash = Cash::with('user')->find($cashId);
        if (!$cash) {
            throw ValidationException::withMessages([
                'cash_id' => ['La caja seleccionada no existe.'],
            ]);
        }

        $this->assertCashAccess($cash, $scope);

        $systemBalance = $this->balanceService->getCashBalanceAsOf(null, null, $cashId, true);
        $ownerName = $cash->user ? (string) $cash->user->name : ('Usuario #' . $cash->user_id);
        $ref = $cash->reference_number ? " ({$cash->reference_number})" : '';

        return [
            'cash_id' => (int) $cash->id,
            'description' => "Caja POS{$ref} - {$ownerName}",
            'reference_number' => $cash->reference_number,
            'user_id' => (int) $cash->user_id,
            'user_name' => $ownerName,
            'establishment_id' => ($cash->user && $cash->user->establishment_id) ? (int) $cash->user->establishment_id : null,
            'state' => (bool) $cash->state,
            'currency_type_id' => 'PEN',
            'system_balance' => $systemBalance,
            'default_denominations' => self::defaultDenominations(),
        ];
    }

    /**
     * Creates a cash count inside a tenant transaction locking the target Cash row.
     * Computes counted_balance, system_balance, and difference strictly server-side.
     */
    public function create(array $payload, int $userId, array $scope): TreasuryCashCount
    {
        $this->assertTableExists();

        if ($userId <= 0) {
            throw ValidationException::withMessages([
                'user_id' => ['El usuario que registra el arqueo es obligatorio.'],
            ]);
        }

        $cashId = (int) ($payload['cash_id'] ?? 0);
        if ($cashId <= 0) {
            throw ValidationException::withMessages([
                'cash_id' => ['Seleccione una caja válida.'],
            ]);
        }

        $rawDenominations = $payload['denominations'] ?? null;
        if (!is_array($rawDenominations) || empty($rawDenominations)) {
            throw ValidationException::withMessages([
                'denominations' => ['Debe indicar al menos una denominación para registrar el arqueo.'],
            ]);
        }

        [$normalizedDenominations, $countedBalance] = $this->normalizeAndSumDenominations($rawDenominations);

        $countedAt = !empty($payload['counted_at'])
            ? Carbon::parse((string) $payload['counted_at'])
            : Carbon::now();

        if ($countedAt->gt(Carbon::now()->addMinutes(5))) {
            throw ValidationException::withMessages([
                'counted_at' => ['La fecha y hora del arqueo no puede ser futura.'],
            ]);
        }

        $systemDateEnd = !empty($payload['counted_at']) ? $countedAt->format('Y-m-d') : null;

        $notes = isset($payload['notes']) && trim((string) $payload['notes']) !== ''
            ? trim((string) $payload['notes'])
            : null;

        return DB::connection('tenant')->transaction(function () use (
            $cashId,
            $userId,
            $scope,
            $normalizedDenominations,
            $countedBalance,
            $countedAt,
            $systemDateEnd,
            $notes
        ) {
            $cash = Cash::with('user')->lockForUpdate()->find($cashId);
            if (!$cash) {
                throw ValidationException::withMessages([
                    'cash_id' => ['La caja seleccionada no existe.'],
                ]);
            }

            $this->assertCashAccess($cash, $scope);

            $systemBalance = $this->balanceService->getCashBalanceAsOf($systemDateEnd, null, $cashId, true);
            $difference = Money::round($countedBalance - $systemBalance);

            $establishmentId = ($cash->user && $cash->user->establishment_id)
                ? (int) $cash->user->establishment_id
                : (isset($scope['establishment_id']) && (int) $scope['establishment_id'] > 0 ? (int) $scope['establishment_id'] : null);

            $count = TreasuryCashCount::create([
                'cash_id' => (int) $cash->id,
                'establishment_id' => $establishmentId,
                'counted_at' => $countedAt,
                'user_id' => $userId,
                'system_balance' => $systemBalance,
                'counted_balance' => $countedBalance,
                'difference' => $difference,
                'denominations' => $normalizedDenominations,
                'notes' => $notes,
            ]);

            return $count->load(['cash.user', 'user', 'establishment']);
        });
    }

    /**
     * Lists cash counts strictly scoped by $scope.
     */
    public function listCounts(array $filters, array $scope): array
    {
        $this->assertTableExists();

        $isAdmin = !empty($scope['is_admin']);
        $userId = isset($scope['user_id']) && (int) $scope['user_id'] > 0 ? (int) $scope['user_id'] : null;
        $scopeEstId = isset($scope['establishment_id']) && (int) $scope['establishment_id'] > 0
            ? (int) $scope['establishment_id']
            : null;

        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($filters['per_page'] ?? 20)));

        if (!$isAdmin && ($scopeEstId === null || $userId === null)) {
            return [
                'rows' => [],
                'meta' => [
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'total' => 0,
                    'last_page' => 1,
                ],
            ];
        }

        $query = TreasuryCashCount::with(['cash.user', 'user', 'establishment'])
            ->orderBy('counted_at', 'desc')
            ->orderBy('id', 'desc');

        if (!$isAdmin) {
            $query->whereHas('cash', function ($q) use ($userId) {
                $q->where('user_id', $userId);
            });
            $query->where(function ($q) use ($scopeEstId) {
                $q->where('establishment_id', $scopeEstId)->orWhereNull('establishment_id');
            });
        } elseif (!empty($filters['establishment_id'])) {
            $query->where('establishment_id', (int) $filters['establishment_id']);
        }

        if (!empty($filters['cash_id'])) {
            $query->where('cash_id', (int) $filters['cash_id']);
        }
        if (!empty($filters['date_start'])) {
            $query->whereDate('counted_at', '>=', (string) $filters['date_start']);
        }
        if (!empty($filters['date_end'])) {
            $query->whereDate('counted_at', '<=', (string) $filters['date_end']);
        }

        $statusFilter = !empty($filters['status']) ? strtolower(trim((string) $filters['status'])) : null;
        if ($statusFilter === 'balanced') {
            $query->where('difference', 0);
        } elseif ($statusFilter === 'surplus') {
            $query->where('difference', '>', 0);
        } elseif ($statusFilter === 'shortage') {
            $query->where('difference', '<', 0);
        }

        $total = (clone $query)->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $items = $query->forPage($page, $perPage)->get();

        $rows = [];
        foreach ($items as $item) {
            $rows[] = $this->formatCountRow($item);
        }

        return [
            'rows' => $rows,
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $lastPage,
            ],
        ];
    }

    /**
     * Finds a single cash count and enforces ownership/establishment access.
     */
    public function findForUser(int $id, array $scope): TreasuryCashCount
    {
        $this->assertTableExists();

        $count = TreasuryCashCount::with(['cash.user', 'user', 'establishment'])->findOrFail($id);

        if (!$count->cash) {
            if (empty($scope['is_admin'])) {
                throw new AuthorizationException('No tiene permiso para acceder a este arqueo de caja.');
            }

            return $count;
        }

        $this->assertCashAccess($count->cash, $scope);

        return $count;
    }

    public function formatCountRow(TreasuryCashCount $count): array
    {
        $diff = Money::round((float) $count->difference);
        if ($diff === 0.0) {
            $status = 'balanced';
            $statusLabel = 'Cuadrado';
        } elseif ($diff > 0.0) {
            $status = 'surplus';
            $statusLabel = 'Sobrante';
        } else {
            $status = 'shortage';
            $statusLabel = 'Faltante';
        }

        $cash = $count->cash;
        $ownerName = ($cash && $cash->user) ? (string) $cash->user->name : ($cash ? ('Usuario #' . $cash->user_id) : '-');
        $ref = ($cash && $cash->reference_number) ? " ({$cash->reference_number})" : '';

        return [
            'id' => (int) $count->id,
            'cash_id' => (int) $count->cash_id,
            'cash_description' => $cash ? "Caja POS{$ref} - {$ownerName}" : ('Caja #' . $count->cash_id),
            'establishment_id' => $count->establishment_id ? (int) $count->establishment_id : null,
            'establishment_description' => $count->establishment ? (string) $count->establishment->description : '-',
            'counted_at' => $count->counted_at ? $count->counted_at->format('Y-m-d H:i:s') : null,
            'user_id' => (int) $count->user_id,
            'user_name' => $count->user ? (string) $count->user->name : ('Usuario #' . $count->user_id),
            'currency_type_id' => 'PEN',
            'system_balance' => Money::round((float) $count->system_balance),
            'counted_balance' => Money::round((float) $count->counted_balance),
            'difference' => $diff,
            'status' => $status,
            'status_label' => $statusLabel,
            'denominations' => is_array($count->denominations) ? $count->denominations : [],
            'notes' => $count->notes,
        ];
    }

    // ---------------------------------------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------------------------------------

    private function assertCashAccess(Cash $cash, array $scope): void
    {
        if (!empty($scope['is_admin'])) {
            return;
        }

        $userId = isset($scope['user_id']) && (int) $scope['user_id'] > 0 ? (int) $scope['user_id'] : null;
        $scopeEstId = isset($scope['establishment_id']) && (int) $scope['establishment_id'] > 0
            ? (int) $scope['establishment_id']
            : null;

        if ($userId === null || $scopeEstId === null) {
            throw new AuthorizationException('Su usuario no tiene una sede asignada para operar arqueos de caja.');
        }

        if ((int) $cash->user_id !== $userId) {
            throw new AuthorizationException('No tiene permiso para acceder al arqueo de una caja de otro usuario.');
        }

        $cashEstId = ($cash->user && $cash->user->establishment_id) ? (int) $cash->user->establishment_id : null;
        if ($cashEstId !== null && $cashEstId !== $scopeEstId) {
            throw new AuthorizationException('No tiene permiso para acceder al arqueo de una caja de otra sede.');
        }
    }

    /**
     * @return array{0: array<int, array{value: float, type: string, label: string, qty: int, subtotal: float}>, 1: float}
     */
    private function normalizeAndSumDenominations(array $raw): array
    {
        if (count($raw) > 50) {
            throw ValidationException::withMessages([
                'denominations' => ['No se pueden registrar más de 50 denominaciones en un mismo arqueo.'],
            ]);
        }

        $normalized = [];
        $seenValues = [];
        $total = 0.0;

        foreach (array_values($raw) as $idx => $item) {
            if (!is_array($item)) {
                throw ValidationException::withMessages([
                    "denominations.{$idx}" => ['Cada denominación debe tener valor y cantidad válidos.'],
                ]);
            }

            if (!isset($item['value']) || !is_numeric($item['value']) || (float) $item['value'] <= 0.0 || (float) $item['value'] > 100000.0) {
                throw ValidationException::withMessages([
                    "denominations.{$idx}.value" => ['El valor de la denominación debe ser mayor a cero y menor o igual a 100,000.'],
                ]);
            }

            $qtyRaw = $item['qty'] ?? 0;
            if (!is_numeric($qtyRaw) || (float) $qtyRaw < 0 || (float) $qtyRaw > 1000000 || floor((float) $qtyRaw) !== (float) $qtyRaw) {
                throw ValidationException::withMessages([
                    "denominations.{$idx}.qty" => ['La cantidad debe ser un número entero entre 0 y 1,000,000.'],
                ]);
            }

            $value = Money::round((float) $item['value']);
            $valueKey = Money::format($value);
            if (isset($seenValues[$valueKey])) {
                throw ValidationException::withMessages([
                    'denominations' => ["La denominación S/ {$valueKey} está duplicada. Consolide las cantidades en una sola fila."],
                ]);
            }
            $seenValues[$valueKey] = true;

            $qty = (int) $qtyRaw;
            $subtotal = Money::round($value * $qty);
            $type = $value >= 10.0 ? 'bill' : 'coin';

            $normalized[] = [
                'value' => $value,
                'type' => $type,
                'label' => ($type === 'bill' ? 'Billete' : 'Moneda') . ' S/ ' . number_format($value, 2, '.', ''),
                'qty' => $qty,
                'subtotal' => $subtotal,
            ];

            $total = Money::round($total + $subtotal);
            if ($total > Money::MAX_AMOUNT) {
                throw ValidationException::withMessages([
                    'denominations' => ['El saldo contado excede el límite máximo permitido.'],
                ]);
            }
        }

        return [$normalized, $total];
    }

    private function assertTableExists(): void
    {
        $exists = false;
        if (Model::getConnectionResolver()) {
            try {
                $exists = DB::connection('tenant')->getSchemaBuilder()->hasTable('treasury_cash_counts');
            } catch (\Throwable $e) {
                $exists = false;
            }
        }

        if (!$exists) {
            throw ValidationException::withMessages([
                'cash_id' => ['La tabla de arqueos de caja aún no está migrada. Ejecute las migraciones del tenant.'],
            ]);
        }
    }
}
