<?php

namespace Modules\Sale\Services;

use App\CoreFacturalo\Helpers\Igv\IgvRate;
use Carbon\Carbon;
use Exception;

/**
 * Pure builder (no database access) of the document payload issued for one billing period of a contract.
 *
 * It follows the same policy as documents generated from quotations: taxed rows (affectation 10) are
 * re-rated with the IGV percentage valid on the issue date, keeping the price with IGV (unit_price) as
 * the source of truth. Rows of other affectations and rows carrying their own discounts/charges keep the
 * stored amounts (same rule as resources/js/helpers/functions.js::rerateDocumentRows).
 */
final class ContractBillingPayload
{
    const TAXED_IDS = ['10', '20', '30', '40'];

    /**
     * @param array $contract {
     *   id, number_full, establishment_id, customer_id, currency_type_id, exchange_rate_sale,
     *   has_global_adjustments (bool), items: [ {item_id, item:{unit_type_id,...}, quantity, unit_price, unit_value,
     *   affectation_igv_type_id, price_type_id, percentage_igv, igv_general_rate(bool), discounts, charges, total_* ...} ]
     * }
     * @param array $options {
     *   document_type_id ('01'|'03'), series_id, billing_date (Y-m-d, billed period), issue_date (Y-m-d, defaults to billing_date), reduced_regime (bool),
     *   exchange_rate_sale (float|null: overrides the contract rate), time_of_issue (H:i:s),
     *   send_email (bool), send_xml_signed (bool|null: only forwarded when false)
     * }
     */
    public static function build(array $contract, array $options): array
    {
        if (!empty($contract['has_global_adjustments'])) {
            throw new Exception('Los contratos con descuentos, cargos o anticipos globales no se pueden facturar de forma recurrente.');
        }
        if (empty($contract['items'])) {
            throw new Exception('El contrato no tiene items para facturar.');
        }

        // billing_date identifies the billed period; the document is issued (and rated) on issue_date.
        $billingDate = Carbon::parse($options['billing_date'])->format('Y-m-d');
        $issueDate = Carbon::parse($options['issue_date'] ?? $billingDate)->format('Y-m-d');
        $reduced = (bool) ($options['reduced_regime'] ?? false);

        $items = [];
        foreach ($contract['items'] as $row) {
            $items[] = self::rerateRow($row, $issueDate, $reduced);
        }

        $totals = self::totals($items);

        $actions = [
            'send_email' => (bool) ($options['send_email'] ?? false),
            'format_pdf' => 'a4',
        ];
        if (array_key_exists('send_xml_signed', $options) && $options['send_xml_signed'] === false) {
            $actions['send_xml_signed'] = false;
        }

        $exchangeRate = $options['exchange_rate_sale'] ?? $contract['exchange_rate_sale'] ?? 1;

        return array_merge([
            'operation_type_id' => '0101',
            'document_type_id' => $options['document_type_id'],
            'series_id' => $options['series_id'],
            'number' => '#',
            'establishment_id' => $contract['establishment_id'],
            'customer_id' => $contract['customer_id'],
            'currency_type_id' => $contract['currency_type_id'],
            'exchange_rate_sale' => (float) $exchangeRate,
            'date_of_issue' => $issueDate,
            'time_of_issue' => $options['time_of_issue'] ?? Carbon::now()->format('H:i:s'),
            'date_of_due' => $issueDate,
            'purchase_order' => null,
            'payment_condition_id' => '01',
            'payments' => [],
            'fee' => [],
            'items' => $items,
            'charges' => [],
            'discounts' => [],
            'attributes' => [],
            'guides' => [],
            'hotel' => [],
            'total_prepayment' => 0,
            'total_discount' => round(array_sum(array_column($items, 'total_discount')), 2),
            'total_charge' => round(array_sum(array_column($items, 'total_charge')), 2),
            'additional_information' => self::additionalInformation($contract, $billingDate),
            'actions' => $actions,
        ], $totals);
    }

    public static function additionalInformation(array $contract, string $billingDate): string
    {
        $text = 'Servicio del periodo ' . RecurrenceSchedule::periodLabel($billingDate);
        if (!empty($contract['number_full'])) {
            $text .= ' - Contrato ' . $contract['number_full'];
        }

        return $text;
    }

    /**
     * Re-rates one row for the issue date (see class docblock).
     */
    public static function rerateRow(array $row, string $issueDate, bool $reducedRegime): array
    {
        $affectation = (string) $row['affectation_igv_type_id'];
        $hasAdjustments = !empty($row['discounts']) || !empty($row['charges']);

        $out = $row;
        $out['affectation_igv_type_id'] = $affectation;
        $out['price_type_id'] = $row['price_type_id'] ?? '01';
        $out['system_isc_type_id'] = $row['system_isc_type_id'] ?? null;
        $out['discounts'] = $row['discounts'] ?? [];
        $out['charges'] = $row['charges'] ?? [];
        $out['total_discount'] = (float) ($row['total_discount'] ?? 0);
        $out['total_charge'] = (float) ($row['total_charge'] ?? 0);

        if ((float) ($row['total_isc'] ?? 0) > 0 || (float) ($row['total_other_taxes'] ?? 0) > 0
            || !empty($row['system_isc_type_id'])) {
            throw new Exception('Los items con ISC u otros tributos no se pueden facturar de forma recurrente.');
        }

        if ($affectation !== '10' || $hasAdjustments) {
            return $out;
        }

        $rate = IgvRate::forDate($issueDate, $reducedRegime, (bool) ($row['igv_general_rate'] ?? false));
        $quantity = (float) $row['quantity'];
        $unitPrice = (float) $row['unit_price'];

        // The price with IGV is exact: total = price x qty; the base is derived from it and the IGV is the remainder.
        $total = round($unitPrice * $quantity, 2);
        $totalValue = round($total / (1 + $rate / 100), 2);
        $totalIgv = round($total - $totalValue, 2);

        $out['unit_value'] = $unitPrice / (1 + $rate / 100);
        $out['percentage_igv'] = $rate;
        $out['total_base_igv'] = $totalValue;
        $out['total_value'] = $totalValue;
        $out['total_igv'] = $totalIgv;
        $out['total_taxes'] = $totalIgv;
        $out['total'] = $total;

        return $out;
    }

    /**
     * Document totals, same grouping as the contract form (calculateTotal).
     */
    public static function totals(array $items): array
    {
        $sum = static function (array $ids, string $field) use ($items) {
            $total = 0.0;
            foreach ($items as $row) {
                if (in_array((string) $row['affectation_igv_type_id'], $ids, true)) {
                    $total += (float) ($row[$field] ?? 0);
                }
            }

            return round($total, 2);
        };

        $freeValue = 0.0;
        $allValue = 0.0;
        foreach ($items as $row) {
            $allValue += (float) ($row['total_value'] ?? 0);
            if (!in_array((string) $row['affectation_igv_type_id'], self::TAXED_IDS, true)) {
                $freeValue += (float) ($row['total_value'] ?? 0);
            }
        }

        $totalIgv = $sum(self::TAXED_IDS, 'total_igv');

        return [
            'total_taxed' => $sum(['10'], 'total_value'),
            'total_exonerated' => $sum(['20'], 'total_value'),
            'total_unaffected' => $sum(['30'], 'total_value'),
            'total_exportation' => $sum(['40'], 'total_value'),
            'total_free' => round($freeValue, 2),
            'total_igv' => $totalIgv,
            'total_taxes' => $totalIgv,
            'total_value' => round($allValue, 2),
            'total' => $sum(self::TAXED_IDS, 'total'),
        ];
    }
}
