<?php

namespace Modules\Treasury\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Modules\Treasury\Models\TreasuryTaxRate;
use Modules\Treasury\Support\Money;

/**
 * Reads versioned regulatory tax rates and thresholds from treasury_tax_rates (Decision D7).
 */
class TaxRateRepository
{
    public const TAX_ITF = 'itf';
    public const TAX_BANCARIZATION_PEN = 'bancarization_threshold_pen';
    public const TAX_BANCARIZATION_USD = 'bancarization_threshold_usd';
    public const TAX_RETENTION_IGV = 'retention_igv';
    public const TAX_PERCEPTION_IGV = 'perception_igv';
    public const TAX_DETRACTION = 'detraction';

    public function getEffective(string $tax, string $date, ?string $code = null): ?TreasuryTaxRate
    {
        if (!$this->hasTable('treasury_tax_rates')) {
            return null;
        }

        $query = TreasuryTaxRate::query()
            ->where('tax', $tax)
            ->effectiveOn($date);

        if ($code === null) {
            $query->whereNull('code');
        } else {
            $query->where('code', $code);
        }

        return $query->first();
    }

    public function getItfRate(string $date): float
    {
        $record = $this->getEffective(self::TAX_ITF, $date);

        if (!$record || $record->rate === null || $record->rate <= 0) {
            return 0.0;
        }

        return (float) $record->rate;
    }

    /**
     * Resolves the effective bancarization threshold (Ley 28194 art. 4) in the operation's currency.
     *
     * - PEN -> bancarization_threshold_pen
     * - USD -> bancarization_threshold_usd
     * - Other currencies -> converts PEN threshold using SBS weighted-average sale exchange rate.
     */
    public function getBancarizationThreshold(
        string $currencyTypeId,
        string $date,
        ?float $exchangeRateSale = null
    ): ?float {
        $currency = strtoupper(trim($currencyTypeId));

        if ($currency === 'PEN') {
            $record = $this->getEffective(self::TAX_BANCARIZATION_PEN, $date);

            return ($record && $record->amount !== null) ? Money::round((float) $record->amount) : null;
        }

        if ($currency === 'USD') {
            $record = $this->getEffective(self::TAX_BANCARIZATION_USD, $date);

            return ($record && $record->amount !== null) ? Money::round((float) $record->amount) : null;
        }

        // For other currencies, Ley 28194 art. 4 converts using SBS weighted-average sale rate.
        $penRecord = $this->getEffective(self::TAX_BANCARIZATION_PEN, $date);
        if (!$penRecord || $penRecord->amount === null || $exchangeRateSale === null || $exchangeRateSale <= 0) {
            return null;
        }

        return Money::round(((float) $penRecord->amount) / $exchangeRateSale);
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
