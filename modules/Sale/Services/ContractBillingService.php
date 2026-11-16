<?php

namespace Modules\Sale\Services;

use App\CoreFacturalo\Facturalo;
use App\CoreFacturalo\Requests\Inputs\DocumentInput;
use App\CoreFacturalo\Requests\Web\Validation\DocumentValidation;
use App\Models\Tenant\Configuration;
use App\Models\Tenant\ExchangeRate;
use App\Models\Tenant\Item;
use App\Models\Tenant\Series;
use App\Models\Tenant\User;
use Carbon\Carbon;
use Exception;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Sale\Models\Contract;
use Modules\Sale\Models\ContractBilling;
use Throwable;

/**
 * Issues the recurrent invoice/receipt of a contract for one period.
 *
 * Idempotency: contract_billings has UNIQUE(contract_id, period_date) and the contract row is locked while a
 * period is issued, so a period is billed at most once. A failed period keeps its row (status failed) and can be
 * retried; next_billing_date only advances after a successful issue, so failed periods stay pending.
 */
class ContractBillingService
{
    const MODE_MANUAL = 'manual';
    const MODE_AUTOMATIC = 'automatic';

    /** Contract states that never bill: 09 rejected, 11 voided. */
    const BLOCKED_STATES = ['09', '11'];

    /** Automatic mode stops retrying a failed period after this many failed attempts (manual retry stays possible). */
    const MAX_AUTOMATIC_ATTEMPTS = 3;

    public static function retryCapReached(int $attempts): bool
    {
        return $attempts >= self::MAX_AUTOMATIC_ATTEMPTS;
    }

    /**
     * A period can be issued only from the contract start and never in the future.
     */
    public static function assertPeriodInRange(string $period, $startDate, string $today): void
    {
        $start = $startDate ? Carbon::parse($startDate)->format('Y-m-d') : null;
        if ($period > $today) {
            throw new Exception('El periodo indicado aún no ha llegado.');
        }
        if ($start !== null && $period < $start) {
            throw new Exception('El periodo indicado es anterior a la fecha de inicio del contrato.');
        }
    }

    /**
     * Pure rule shared by the query below: is this contract due on $date?
     */
    public static function isDue(array $contract, string $date): bool
    {
        if (empty($contract['recurrence_enabled'])) {
            return false;
        }
        if (in_array((string) ($contract['state_type_id'] ?? ''), self::BLOCKED_STATES, true)) {
            return false;
        }
        if (empty($contract['recurrence_start_date']) || empty($contract['next_billing_date'])) {
            return false;
        }

        $day = Carbon::parse($date)->format('Y-m-d');
        $start = Carbon::parse($contract['recurrence_start_date'])->format('Y-m-d');
        $next = Carbon::parse($contract['next_billing_date'])->format('Y-m-d');
        $end = empty($contract['recurrence_end_date']) ? null : Carbon::parse($contract['recurrence_end_date'])->format('Y-m-d');

        return $start <= $day && $next <= $day && ($end === null || $end >= $next);
    }

    /**
     * @return Collection|Contract[]
     */
    public function dueContracts(string $date, ?string $mode = null, bool $respectRetryCap = false, bool $scopeUser = false)
    {
        $query = Contract::query()
            ->where('recurrence_enabled', true)
            ->whereNotIn('state_type_id', self::BLOCKED_STATES)
            ->whereDate('recurrence_start_date', '<=', $date)
            ->whereDate('next_billing_date', '<=', $date)
            ->where(function ($q) {
                $q->whereNull('recurrence_end_date')->orWhereColumn('recurrence_end_date', '>=', 'next_billing_date');
            })
            ->orderBy('next_billing_date')
            ->orderBy('id');

        if ($mode) {
            $query->where('recurrence_mode', $mode);
        }
        if ($scopeUser) {
            $query->whereTypeUser();
        }

        $contracts = $query->get();

        if ($respectRetryCap) {
            $contracts = $contracts->reject(function (Contract $contract) {
                $attempts = (int) ContractBilling::query()
                    ->where('contract_id', $contract->id)
                    ->whereDate('period_date', $contract->next_billing_date)
                    ->where('status', ContractBilling::STATUS_FAILED)
                    ->value('attempts');

                return self::retryCapReached($attempts);
            })->values();
        }

        return $contracts;
    }

    /**
     * First billing date for a contract being configured (null when recurrence is off).
     */
    public static function initialNextBillingDate(array $data, ?string $today = null): ?string
    {
        if (empty($data['recurrence_enabled']) || empty($data['recurrence_start_date'])) {
            return null;
        }

        $first = RecurrenceSchedule::first(
            $data['recurrence_start_date'],
            $data['recurrence_period'],
            (int) ($data['recurrence_interval'] ?: 1),
            $data['recurrence_day'] ?? null
        );

        // A backdated start skips the past periods unless the user asked to bill them too.
        $today = Carbon::parse($today ?: Carbon::today())->startOfDay();
        if (empty($data['recurrence_backfill']) && $first->lt($today)) {
            $first = RecurrenceSchedule::next(
                $data['recurrence_start_date'],
                $data['recurrence_period'],
                (int) ($data['recurrence_interval'] ?: 1),
                $today->copy()->subDay(),
                $data['recurrence_day'] ?? null
            );
        }

        if (!empty($data['recurrence_end_date']) && $first->gt(Carbon::parse($data['recurrence_end_date']))) {
            return null;
        }

        return $first->format('Y-m-d');
    }

    /**
     * Issues the document of one period.
     *
     * @param array $options issue_date (default today), send_xml_signed (false forces "do not send to SUNAT"),
     *                       send_email (default true), exchange_rate_sale
     *
     * @throws ContractAlreadyBilledException when the period was already issued
     * @throws Exception on any validation/emission failure (a failed row is recorded first)
     */
    public function issue(Contract $contract, $billingDate, string $mode, array $options = []): ContractBilling
    {
        $billingDate = Carbon::parse($billingDate)->format('Y-m-d');
        $contractId = $contract->id;
        $previousUser = Auth::user();

        try {
            $facturalo = null;

            $billing = DB::connection('tenant')->transaction(function () use ($contractId, $billingDate, $mode, $options, &$facturalo) {
                /** @var Contract $locked */
                $locked = Contract::query()->lockForUpdate()->findOrFail($contractId);

                $this->assertBillable($locked);

                $existing = ContractBilling::query()
                    ->where('contract_id', $contractId)->whereDate('period_date', $billingDate)->first();
                self::assertNotAlreadyIssued($existing, $locked->number_full, $billingDate);

                $actor = $locked->billing_user_id ? User::find($locked->billing_user_id) : null;
                if (!$actor) {
                    throw new Exception('El usuario emisor del contrato no existe o no está definido. Edite y guarde el contrato para asignarlo.');
                }
                Auth::setUser($actor);

                $payload = $this->buildPayload($locked, $billingDate, $options);
                $inputs = DocumentInput::set(DocumentValidation::validation($payload));

                $facturalo = new Facturalo();
                $facturalo->save($inputs);
                $facturalo->createXmlUnsigned();
                $facturalo->signXmlUnsigned();
                $facturalo->updateHash();
                $facturalo->updateQr();
                $facturalo->createPdf();
                $facturalo->senderXmlSignedBill();

                $document = $facturalo->getDocument();

                $billing = ContractBilling::query()->updateOrCreate(
                    ['contract_id' => $contractId, 'period_date' => $billingDate],
                    ['document_id' => $document->id, 'status' => ContractBilling::STATUS_ISSUED, 'error' => null, 'mode' => $mode]
                );

                $this->advance($locked, $billingDate);

                return $billing;
            });
        } catch (ContractAlreadyBilledException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->recordFailure($contractId, $billingDate, $mode, $e);
            throw $e instanceof Exception ? $e : new Exception($e->getMessage(), 0, $e);
        } finally {
            $this->restoreUser($previousUser);
        }

        $this->sendEmailQuietly($facturalo);

        return $billing;
    }

    /**
     * Idempotency guard: an issued period can never be billed again; a failed one can be retried.
     *
     * @throws ContractAlreadyBilledException
     */
    public static function assertNotAlreadyIssued(?ContractBilling $existing, string $contractNumber, string $billingDate): void
    {
        if ($existing && $existing->status === ContractBilling::STATUS_ISSUED) {
            throw new ContractAlreadyBilledException("El periodo {$billingDate} del contrato {$contractNumber} ya fue facturado.");
        }
    }

    /**
     * Builds the document payload of a period (reads the DB; the math lives in ContractBillingPayload).
     */
    public function buildPayload(Contract $contract, string $billingDate, array $options = []): array
    {
        $documentTypeId = $contract->billing_document_type_id ?: '01';
        $series = $this->resolveSeries($contract, $documentTypeId);
        $issueDate = Carbon::parse($options['issue_date'] ?? Carbon::today())->format('Y-m-d');

        $customer = $contract->customer;
        $email = is_object($customer) ? ($customer->email ?? null) : null;
        $sendEmail = ($options['send_email'] ?? true) && $email && filter_var($email, FILTER_VALIDATE_EMAIL);

        $data = [
            'id' => $contract->id,
            'number_full' => $contract->number_full,
            'establishment_id' => $contract->establishment_id,
            'customer_id' => $contract->customer_id,
            'currency_type_id' => $contract->currency_type_id,
            'exchange_rate_sale' => $this->exchangeRate($contract, $issueDate, $options),
            'has_global_adjustments' => $this->hasGlobalAdjustments($contract),
            'items' => $this->itemsAsArrays($contract),
        ];

        return ContractBillingPayload::build($data, [
            'document_type_id' => $documentTypeId,
            'series_id' => $series->id,
            'billing_date' => $billingDate,
            'issue_date' => $issueDate,
            'reduced_regime' => (bool) optional(Configuration::first())->igv_reduced_regime,
            'exchange_rate_sale' => $data['exchange_rate_sale'],
            'send_email' => (bool) $sendEmail,
            'send_xml_signed' => $options['send_xml_signed'] ?? null,
        ]);
    }

    /**
     * Exchange rate: the stored sale rate of the issue date (latest on or before it); falls back to the rate
     * saved in the contract.
     */
    private function exchangeRate(Contract $contract, string $issueDate, array $options): float
    {
        if (!empty($options['exchange_rate_sale'])) {
            return (float) $options['exchange_rate_sale'];
        }

        $rate = ExchangeRate::query()->whereDate('date', '<=', $issueDate)->orderBy('date', 'desc')->first();
        if ($rate && (float) $rate->sale > 0) {
            return (float) $rate->sale;
        }

        return (float) ($contract->exchange_rate_sale ?: 1);
    }

    private function resolveSeries(Contract $contract, string $documentTypeId): Series
    {
        $query = Series::query()
            ->where('establishment_id', $contract->establishment_id)
            ->where('document_type_id', $documentTypeId)
            ->where('contingency', false);

        if ($contract->billing_series) {
            $series = (clone $query)->where('number', $contract->billing_series)->first();
            if (!$series) {
                throw new Exception("La serie {$contract->billing_series} no existe, es de contingencia o no pertenece al establecimiento del contrato.");
            }

            return $series;
        }

        $series = $query->orderBy('id')->first();
        if (!$series) {
            throw new Exception('No hay una serie (que no sea de contingencia) para el tipo de comprobante y el establecimiento del contrato.');
        }

        return $series;
    }

    private function hasGlobalAdjustments(Contract $contract): bool
    {
        foreach (['discounts', 'charges', 'prepayments'] as $field) {
            if (!empty((array) $contract->{$field})) {
                return true;
            }
        }

        return false;
    }

    private function itemsAsArrays(Contract $contract): array
    {
        $rows = [];
        foreach ($contract->items as $row) {
            $item = json_decode(json_encode($row->item), true) ?: [];
            $catalogItem = Item::find($row->item_id);

            $rows[] = [
                'item_id' => $row->item_id,
                'item' => $item,
                'quantity' => $row->quantity,
                'unit_value' => $row->unit_value,
                'unit_price' => $row->unit_price,
                'price_type_id' => $row->price_type_id,
                'affectation_igv_type_id' => $row->affectation_igv_type_id,
                'percentage_igv' => $row->percentage_igv,
                'total_base_igv' => $row->total_base_igv,
                'total_igv' => $row->total_igv,
                'system_isc_type_id' => $row->system_isc_type_id,
                'total_base_isc' => $row->total_base_isc,
                'percentage_isc' => $row->percentage_isc,
                'total_isc' => $row->total_isc,
                'total_base_other_taxes' => $row->total_base_other_taxes,
                'percentage_other_taxes' => $row->percentage_other_taxes,
                'total_other_taxes' => $row->total_other_taxes,
                'total_taxes' => $row->total_taxes,
                'total_value' => $row->total_value,
                'total_charge' => $row->total_charge,
                'total_discount' => $row->total_discount,
                'total' => $row->total,
                'attributes' => $row->attributes ? json_decode(json_encode($row->attributes), true) : null,
                'discounts' => $row->discounts ? array_values(json_decode(json_encode($row->discounts), true) ?: []) : [],
                'charges' => $row->charges ? array_values(json_decode(json_encode($row->charges), true) ?: []) : [],
                'igv_general_rate' => $catalogItem ? (bool) $catalogItem->igv_general_rate : false,
            ];
        }

        return $rows;
    }

    private function assertBillable(Contract $contract): void
    {
        if (!$contract->recurrence_enabled) {
            throw new Exception('El contrato no tiene la facturación recurrente activada.');
        }
        if (in_array((string) $contract->state_type_id, self::BLOCKED_STATES, true)) {
            throw new Exception('El contrato esta anulado o rechazado y no se puede facturar.');
        }
    }

    /**
     * Moves next_billing_date after $billingDate, only when this period is the current pending one
     * (retrying an older failed period never moves the pointer back or forward).
     */
    private function advance(Contract $contract, string $billingDate): void
    {
        $current = $contract->next_billing_date ? $contract->next_billing_date->format('Y-m-d') : null;
        if ($current !== null && $billingDate < $current) {
            return;
        }

        $next = RecurrenceSchedule::advance(
            $contract->recurrence_start_date,
            $contract->recurrence_end_date ? $contract->recurrence_end_date->format('Y-m-d') : null,
            $contract->recurrence_period,
            (int) $contract->recurrence_interval,
            $billingDate,
            $contract->recurrence_day
        );

        $contract->next_billing_date = $next ? $next->format('Y-m-d') : null;
        $contract->save();
    }

    private function recordFailure(int $contractId, string $billingDate, string $mode, Throwable $e): void
    {
        try {
            $row = ContractBilling::query()->firstOrNew(['contract_id' => $contractId, 'period_date' => $billingDate]);
            $row->fill([
                'document_id' => null, 'status' => ContractBilling::STATUS_FAILED,
                'error' => mb_substr($e->getMessage(), 0, 2000), 'mode' => $mode,
                'attempts' => (int) $row->attempts + 1,
            ])->save();
        } catch (Throwable $inner) {
            Log::error('contract billing: could not record failure', ['contract_id' => $contractId, 'error' => $inner->getMessage()]);
        }
    }

    private function sendEmailQuietly(?Facturalo $facturalo): void
    {
        if (!$facturalo) {
            return;
        }

        try {
            $facturalo->sendEmail();
        } catch (Throwable $e) {
            Log::warning('contract billing: email not sent', ['error' => $e->getMessage()]);
        }
    }

    private function restoreUser($previousUser): void
    {
        if ($previousUser) {
            Auth::setUser($previousUser);
        } else {
            Auth::guard()->forgetUser();
        }
    }
}
