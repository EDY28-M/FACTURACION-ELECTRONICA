<?php

namespace Modules\Treasury\Services;

use App\Models\Tenant\Cash;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Treasury\Models\TreasuryMovement;
use Modules\Treasury\Support\Money;

/**
 * Integrates active POS-cash TreasuryMovements with Cash POS closing and reports.
 *
 * Always guards queries with hasTable('treasury_movements') so legacy schemas
 * and Phase 0 characterization tests continue working without modification.
 */
class CashTreasuryIntegrationService
{
    public function getActiveMovementsForCash(Cash $cash, ?string $dateEnd = null): Collection
    {
        if (!$this->hasTable('treasury_movements')) {
            return collect();
        }

        $effectiveEnd = $dateEnd ?: ($cash->date_closed ?: date('Y-m-d'));

        return TreasuryMovement::active()
            ->where('destination_type', Cash::class)
            ->where('destination_id', $cash->id)
            ->where('date', '>=', $cash->date_opening)
            ->where('date', '<=', $effectiveEnd)
            ->orderBy('date', 'asc')
            ->orderBy('id', 'asc')
            ->get();
    }

    /**
     * @return array{movements: Collection, income: float, egress: float, net: float}
     */
    public function summarizeForCash(Cash $cash, ?string $dateEnd = null): array
    {
        $movements = $this->getActiveMovementsForCash($cash, $dateEnd);

        $income = 0.0;
        $egress = 0.0;

        foreach ($movements as $movement) {
            $amountPen = (float) ($movement->amount_pen ?? $movement->amount ?? 0.0);
            if ((int) $movement->direction >= 0) {
                $income += $amountPen;
            } else {
                $egress += $amountPen;
            }
        }

        $income = Money::round($income);
        $egress = Money::round($egress);
        $net = Money::round($income - $egress);

        return [
            'movements' => $movements,
            'income' => $income,
            'egress' => $egress,
            'net' => $net,
        ];
    }

    public function sumNetForCash(Cash $cash, ?string $dateEnd = null): float
    {
        $summary = $this->summarizeForCash($cash, $dateEnd);

        return $summary['net'];
    }

    private function hasTable(string $table): bool
    {
        if (!Model::getConnectionResolver()) {
            return false;
        }

        try {
            return DB::connection('tenant')->getSchemaBuilder()->hasTable($table);
        } catch (\Throwable $e) {
            return false;
        }
    }
}
