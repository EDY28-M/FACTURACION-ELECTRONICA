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
    public const TAX_DETRACTION_DEPOSIT_BUSINESS_DAY = 'detraction_deposit_business_day';

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
     * Effective IGV retention rule on a date: rate and minimum operation amount (PEN), or null if none is loaded.
     *
     * @return array{rate: float, min_operation: float, legal_reference: ?string}|null
     */
    public function getRetentionRule(string $date): ?array
    {
        $record = $this->getEffective(self::TAX_RETENTION_IGV, $date);
        if (!$record || $record->rate === null || $record->rate <= 0) {
            return null;
        }

        return [
            'rate' => (float) $record->rate,
            'min_operation' => $record->min_operation !== null ? (float) $record->min_operation : 0.0,
            'legal_reference' => $record->legal_reference,
        ];
    }

    /**
     * Effective IGV perception rate for a class code (general | fuel | agent) on a date.
     *
     * @return array{rate: float, code: string, legal_reference: ?string}|null
     */
    public function getPerceptionRule(string $code, string $date): ?array
    {
        $record = $this->getEffective(self::TAX_PERCEPTION_IGV, $date, $code);
        if (!$record || $record->rate === null || $record->rate <= 0) {
            return null;
        }

        return [
            'rate' => (float) $record->rate,
            'code' => $code,
            'legal_reference' => $record->legal_reference,
        ];
    }

    /**
     * Effective detraction rule for a Catalogo 54 code on a date.
     *
     * @return array{rate: float, min_operation: ?float, legal_reference: ?string}|null
     */
    public function getDetractionRule(string $code, string $date): ?array
    {
        $record = $this->getEffective(self::TAX_DETRACTION, $date, $code);
        if (!$record || $record->rate === null || $record->rate <= 0) {
            return null;
        }

        return [
            'rate' => (float) $record->rate,
            'min_operation' => $record->min_operation !== null ? (float) $record->min_operation : null,
            'legal_reference' => $record->legal_reference,
        ];
    }

    /**
     * Business day of the month after the Registro de Compras entry by which a detraction must be deposited,
     * effective on a date. Null when the row is not loaded (caller falls back to config).
     */
    public function getDetractionDepositBusinessDay(string $date): ?int
    {
        $record = $this->getEffective(self::TAX_DETRACTION_DEPOSIT_BUSINESS_DAY, $date);
        if (!$record || $record->amount === null || (int) $record->amount < 1) {
            return null;
        }

        return (int) $record->amount;
    }

    /**
     * Lists the perception rate classes effective on a date (code => rate).
     *
     * @return array<string, float>
     */
    public function listPerceptionRates(string $date): array
    {
        if (!$this->hasTable('treasury_tax_rates')) {
            return [];
        }

        $rows = TreasuryTaxRate::query()
            ->where('tax', self::TAX_PERCEPTION_IGV)
            ->effectiveOn($date)
            ->get();

        $rates = [];
        foreach ($rows as $row) {
            // effectiveOn orders by valid_from desc, so the first row per code is the latest version.
            if ($row->code !== null && !isset($rates[$row->code]) && $row->rate !== null) {
                $rates[$row->code] = (float) $row->rate;
            }
        }

        return $rates;
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
