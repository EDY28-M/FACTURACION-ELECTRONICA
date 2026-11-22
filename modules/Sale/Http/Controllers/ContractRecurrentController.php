<?php

namespace Modules\Sale\Http\Controllers;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Modules\Sale\Models\Contract;
use Modules\Sale\Models\ContractBilling;
use Modules\Sale\Services\ContractAlreadyBilledException;
use Modules\Sale\Services\ContractBillingService;
use Throwable;

class ContractRecurrentController extends Controller
{
    /** @var ContractBillingService */
    private $service;

    public function __construct(ContractBillingService $service)
    {
        $this->service = $service;
    }

    public function index()
    {
        return view('sale::contracts.recurrent');
    }

    /**
     * Contracts due as of a date (both modes).
     */
    public function pending(Request $request)
    {
        $date = $request->filled('date') ? Carbon::parse($request->input('date'))->format('Y-m-d') : Carbon::today()->format('Y-m-d');

        $records = $this->service->dueContracts($date, null, false, true)->map(function (Contract $contract) {
            $period = $contract->next_billing_date->format('Y-m-d');
            $failed = ContractBilling::where('contract_id', $contract->id)
                ->whereDate('period_date', $period)->where('status', ContractBilling::STATUS_FAILED)->first();
            $attempts = $failed ? (int) $failed->attempts : 0;

            return [
                'contract_id' => $contract->id,
                'number_full' => $contract->number_full,
                'customer_name' => optional($contract->customer)->name,
                'customer_number' => optional($contract->customer)->number,
                'period_date' => $period,
                'period_label' => Carbon::parse($period)->format('m/Y'),
                'mode' => $contract->recurrence_mode,
                'document_type_id' => $contract->billing_document_type_id,
                'currency_type_id' => $contract->currency_type_id,
                'total' => number_format($contract->total, 2, '.', ''),
                'last_error' => $failed ? $failed->error : null,
                'attempts' => $attempts,
                'auto_paused' => $contract->recurrence_mode === ContractBillingService::MODE_AUTOMATIC
                    && ContractBillingService::retryCapReached($attempts),
            ];
        })->values();

        return ['date' => $date, 'data' => $records];
    }

    public function history(Request $request)
    {
        $query = ContractBilling::query()->with(['contract', 'document'])
            ->whereHas('contract', function ($q) {
                $q->whereTypeUser();
            })->orderBy('period_date', 'desc')->orderBy('id', 'desc');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $page = $query->paginate(config('tenant.items_per_page'));

        $page->getCollection()->transform(function (ContractBilling $row) {
            $document = $row->document;

            return [
                'id' => $row->id,
                'contract_id' => $row->contract_id,
                'contract_number' => optional($row->contract)->number_full,
                'customer_name' => optional(optional($row->contract)->customer)->name,
                'period_date' => $row->period_date->format('Y-m-d'),
                'status' => $row->status,
                'mode' => $row->mode,
                'error' => $row->error,
                'document_id' => $row->document_id,
                'document_number' => $document ? $document->number_full : null,
                'document_pdf' => $document ? $document->download_external_pdf : null,
                'total' => $document ? $document->total : null,
                'currency_type_id' => $document ? $document->currency_type_id : null,
                'updated_at' => $row->updated_at ? $row->updated_at->format('Y-m-d H:i:s') : null,
            ];
        });

        return $page;
    }

    /**
     * Manual issue: runs the same service the scheduled command uses, with mode=manual.
     * Accepts only the contract's current pending period or a period whose previous attempt failed.
     */
    public function issue(Request $request)
    {
        $request->validate([
            'items' => 'required|array|min:1|max:10',
            'items.*.contract_id' => 'required|integer',
            'items.*.period_date' => 'required|date',
        ]);

        $results = [];

        foreach ($request->input('items') as $row) {
            $period = Carbon::parse($row['period_date'])->format('Y-m-d');
            $result = ['contract_id' => (int) $row['contract_id'], 'period_date' => $period];

            try {
                $contract = Contract::query()->whereTypeUser()->findOrFail($row['contract_id']);
                $this->assertPeriodAllowed($contract, $period);

                $billing = $this->service->issue($contract, $period, ContractBillingService::MODE_MANUAL);
                $billing->load('document');
                $result += [
                    'status' => 'issued',
                    'document_id' => $billing->document_id,
                    'document_number' => optional($billing->document)->number_full,
                ];
            } catch (ContractAlreadyBilledException $e) {
                $result += ['status' => 'skipped', 'message' => 'No se pudo procesar el contrato.'];
            } catch (Throwable $e) {
                $result += ['status' => 'failed', 'message' => 'No se pudo procesar el contrato.'];
            }

            $results[] = $result;
        }

        return [
            'success' => true,
            'issued' => collect($results)->where('status', 'issued')->count(),
            'failed' => collect($results)->where('status', 'failed')->count(),
            'skipped' => collect($results)->where('status', 'skipped')->count(),
            'results' => $results,
        ];
    }

    private function assertPeriodAllowed(Contract $contract, string $period): void
    {
        $isCurrent = $contract->next_billing_date && $contract->next_billing_date->format('Y-m-d') === $period;
        $isRetry = ContractBilling::where('contract_id', $contract->id)
            ->whereDate('period_date', $period)->where('status', ContractBilling::STATUS_FAILED)->exists();

        if (!$isCurrent && !$isRetry) {
            throw new \Exception('El periodo indicado no es el pendiente del contrato ni un intento fallido.');
        }

        ContractBillingService::assertPeriodInRange($period, $contract->recurrence_start_date, Carbon::today()->format('Y-m-d'));
    }
}
