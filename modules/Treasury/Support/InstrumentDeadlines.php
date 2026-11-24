<?php

namespace Modules\Treasury\Support;

use DateTimeImmutable;
use Modules\Treasury\Models\TreasuryInstrument;
use Modules\Treasury\Services\TaxRateRepository;

/**
 * Legal deadlines of cheques and bills (Ley 27287). The periods are versioned data (treasury_tax_rates) with a
 * config fallback; none of them is hardcoded here. All of them are flagged [REVISAR] in their legal reference.
 *
 * - Cheque presentation deadline: N days (calendar) counted from the issue date; for a deferred cheque
 *   (pago diferido) counted from the payment date (due date).
 * - Deferred cheque: the payment date may be at most M days after the issue date.
 * - Bill protest deadline: N days after maturity (a maturity on a non-business day counts from the next business day), in business days (BusinessDayCalculator + holidays) or calendar
 *   days depending on config treasury.instruments.protest_day_type.
 */
class InstrumentDeadlines
{
    private $rates;
    private $calendar;

    public function __construct(TaxRateRepository $rates, BusinessDayCalculator $calendar)
    {
        $this->rates = $rates;
        $this->calendar = $calendar;
    }

    public function chequePresentationDays(string $onDate): int
    {
        return $this->rates->getInstrumentDays(TaxRateRepository::TAX_CHEQUE_PRESENTATION_DAYS, $onDate)
            ?? (int) config('treasury.instruments.cheque_presentation_days_fallback', 30);
    }

    public function chequeDeferredMaxDays(string $onDate): int
    {
        return $this->rates->getInstrumentDays(TaxRateRepository::TAX_CHEQUE_DEFERRED_MAX_DAYS, $onDate)
            ?? (int) config('treasury.instruments.cheque_deferred_max_days_fallback', 30);
    }

    public function billProtestDays(string $onDate): int
    {
        return $this->rates->getInstrumentDays(TaxRateRepository::TAX_BILL_PROTEST_DAYS, $onDate)
            ?? (int) config('treasury.instruments.bill_protest_days_fallback', 15);
    }

    public function protestDayType(): string
    {
        return config('treasury.instruments.protest_day_type', 'business') === 'calendar' ? 'calendar' : 'business';
    }

    /**
     * @return array{presentation_deadline: ?string, protest_deadline: ?string, deadline_is_estimate: bool}
     */
    public function compute(string $type, string $issueDate, string $dueDate, bool $isDeferred): array
    {
        $result = [
            'presentation_deadline' => null,
            'protest_deadline' => null,
            'deadline_is_estimate' => false,
        ];

        if ($type === TreasuryInstrument::TYPE_CHEQUE_ISSUED || $type === TreasuryInstrument::TYPE_CHEQUE_RECEIVED) {
            $from = $isDeferred ? $dueDate : $issueDate;
            $days = $this->chequePresentationDays($issueDate);
            $result['presentation_deadline'] = (new DateTimeImmutable($from))->modify('+' . $days . ' days')->format('Y-m-d');

            return $result;
        }

        $days = $this->billProtestDays($dueDate);

        // [REVISAR] A maturity that falls on a non-business day (weekend / holiday) is carried to the next business
        // day, and the protest period is counted from there. Source: general rule on non-business maturities; the
        // research document does not cover it.
        $countFrom = $this->calendar->isBusinessDay($dueDate) ? $dueDate : $this->calendar->addBusinessDays($dueDate, 1);

        if ($this->protestDayType() === 'calendar') {
            $result['protest_deadline'] = (new DateTimeImmutable($countFrom))->modify('+' . $days . ' days')->format('Y-m-d');

            return $result;
        }

        $deadline = $this->calendar->addBusinessDays($countFrom, $days);
        $result['protest_deadline'] = $deadline;
        // Without holidays loaded for the years involved the business-day count may be off by the missing days.
        $result['deadline_is_estimate'] = !$this->calendar->hasHolidaysForYear((int) substr($dueDate, 0, 4))
            || !$this->calendar->hasHolidaysForYear((int) substr($deadline, 0, 4));

        return $result;
    }
}
