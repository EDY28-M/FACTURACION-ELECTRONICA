<?php

namespace Modules\Treasury\Services;

use App\Models\Tenant\Purchase;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Treasury\Models\PurchaseFee;
use Modules\Treasury\Support\Money;

/**
 * Manages manual installment schedules for purchases inside tenant transactions (Phase 3).
 * Never calls auth() internally; callers handle authorization before delegating.
 */
class PurchaseFeeService
{
    public const ACCEPTED_STATE_TYPES = ['01', '03', '05', '07', '13'];

    /**
     * Replaces all manual installments for a purchase atomically.
     *
     * @param  array<int, array{date: string, amount: numeric}>  $installments
     * @return array<int, array{id: int, purchase_id: int, date: string, currency_type_id: string, amount: float}>
     */
    public function replaceFees(int $purchaseId, array $installments): array
    {
        $this->assertTableExists();

        if (empty($installments)) {
            throw ValidationException::withMessages([
                'fees' => ['Debe registrar al menos una cuota para la compra.'],
            ]);
        }

        $normalized = [];
        $totalFees = 0.0;

        foreach (array_values($installments) as $index => $row) {
            $rawDate = isset($row['date']) ? trim((string) $row['date']) : '';
            $parsedDate = $this->parseStrictDate($rawDate);
            if ($parsedDate === null) {
                throw ValidationException::withMessages([
                    "fees.{$index}.date" => ['La fecha de vencimiento de la cuota debe tener el formato YYYY-MM-DD.'],
                ]);
            }

            if (!isset($row['amount']) || !is_numeric($row['amount'])) {
                throw ValidationException::withMessages([
                    "fees.{$index}.amount" => ['El importe de la cuota debe ser un número válido.'],
                ]);
            }

            $amount = Money::round((float) $row['amount']);
            if ($amount < Money::MIN_AMOUNT || $amount > Money::MAX_AMOUNT) {
                throw ValidationException::withMessages([
                    "fees.{$index}.amount" => ['El importe de cada cuota debe estar entre 0.01 y 9,999,999,999.99.'],
                ]);
            }

            $totalFees = Money::round($totalFees + $amount);
            $normalized[] = [
                'date' => $parsedDate,
                'amount' => $amount,
            ];
        }

        return DB::connection('tenant')->transaction(function () use ($purchaseId, $normalized, $totalFees) {
            $purchase = Purchase::query()
                ->setEagerLoads([])
                ->lockForUpdate()
                ->findOrFail($purchaseId);

            if (!in_array((string) $purchase->state_type_id, self::ACCEPTED_STATE_TYPES, true)) {
                throw ValidationException::withMessages([
                    'purchase_id' => ['No se pueden programar cuotas en una compra anulada o rechazada.'],
                ]);
            }

            $purchaseTotal = Money::round((float) $purchase->total);
            if ($totalFees > $purchaseTotal + 0.0001) {
                throw ValidationException::withMessages([
                    'fees' => [
                        'La suma de las cuotas (' . Money::format($totalFees) . ') no puede superar el total de la compra (' . Money::format($purchaseTotal) . ').',
                    ],
                ]);
            }

            $currency = strtoupper(trim((string) ($purchase->currency_type_id ?: 'PEN')));

            PurchaseFee::where('purchase_id', $purchase->id)->delete();

            foreach ($normalized as $item) {
                PurchaseFee::create([
                    'purchase_id' => $purchase->id,
                    'date' => $item['date'],
                    'currency_type_id' => $currency,
                    'amount' => $item['amount'],
                ]);
            }

            return PurchaseFee::where('purchase_id', $purchase->id)
                ->orderBy('date', 'asc')
                ->orderBy('id', 'asc')
                ->get()
                ->map(function (PurchaseFee $fee) {
                    return [
                        'id' => (int) $fee->id,
                        'purchase_id' => (int) $fee->purchase_id,
                        'date' => $fee->date ? $fee->date->format('Y-m-d') : null,
                        'currency_type_id' => (string) $fee->currency_type_id,
                        'amount' => Money::round((float) $fee->amount),
                    ];
                })
                ->values()
                ->all();
        });
    }

    /**
     * Deletes all manual installments for a purchase atomically.
     */
    public function deleteFees(int $purchaseId): void
    {
        $this->assertTableExists();

        DB::connection('tenant')->transaction(function () use ($purchaseId) {
            $purchase = Purchase::query()
                ->setEagerLoads([])
                ->lockForUpdate()
                ->findOrFail($purchaseId);

            if (!in_array((string) $purchase->state_type_id, self::ACCEPTED_STATE_TYPES, true)) {
                throw ValidationException::withMessages([
                    'purchase_id' => ['No se pueden modificar cuotas en una compra anulada o rechazada.'],
                ]);
            }

            PurchaseFee::where('purchase_id', $purchase->id)->delete();
        });
    }

    private function assertTableExists(): void
    {
        if (!DB::connection('tenant')->getSchemaBuilder()->hasTable('purchase_fees')) {
            throw ValidationException::withMessages([
                'fees' => ['La tabla de cuotas de compras no está migrada todavía.'],
            ]);
        }
    }

    private function parseStrictDate(string $value): ?string
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            $parsed = Carbon::createFromFormat('Y-m-d', $value);

            return ($parsed && $parsed->format('Y-m-d') === $value) ? $value : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
