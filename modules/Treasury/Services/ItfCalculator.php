<?php

namespace Modules\Treasury\Services;

use Modules\Treasury\Support\Money;

/**
 * Computes Peru Financial Transactions Tax (ITF) from versioned rates in TaxRateRepository.
 */
class ItfCalculator
{
    private $taxRateRepository;

    public function __construct(TaxRateRepository $taxRateRepository)
    {
        $this->taxRateRepository = $taxRateRepository;
    }

    /**
     * @return array{applies: bool, rate: float, amount: float, reason: ?string}
     */
    public function calculate(
        float $amount,
        string $date,
        bool $isBankDestination,
        bool $isItfExempt = false,
        bool $isSameHolderTransfer = false
    ): array {
        if (!$isBankDestination) {
            return [
                'applies' => false,
                'rate' => 0.0,
                'amount' => 0.0,
                'reason' => 'cash_destination',
            ];
        }

        if ($isItfExempt) {
            return [
                'applies' => false,
                'rate' => 0.0,
                'amount' => 0.0,
                'reason' => 'account_itf_exempt',
            ];
        }

        if ($isSameHolderTransfer) {
            return [
                'applies' => false,
                'rate' => 0.0,
                'amount' => 0.0,
                'reason' => 'same_holder_transfer',
            ];
        }

        $rate = $this->taxRateRepository->getItfRate($date);
        if ($rate <= 0 || $amount <= 0) {
            return [
                'applies' => false,
                'rate' => $rate,
                'amount' => 0.0,
                'reason' => $rate <= 0 ? 'rate_not_configured' : 'non_positive_amount',
            ];
        }

        $itfAmount = Money::round($amount * $rate);

        return [
            'applies' => true,
            'rate' => $rate,
            'amount' => $itfAmount,
            'reason' => null,
        ];
    }
}
