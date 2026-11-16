<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Hyn\Tenancy\Environment;
use Hyn\Tenancy\Models\Website;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Modules\Sale\Services\ContractAlreadyBilledException;
use Modules\Sale\Services\ContractBillingService;
use Throwable;

/**
 * Issues the recurrent invoices of every tenant for the contracts set to "automatic" mode.
 * Manual contracts are never billed here: they are issued by a user from Ventas > Contratos > Facturacion recurrente.
 */
class ContractsBillRecurrentCommand extends Command
{
    protected $signature = 'contracts:bill-recurrent
                            {--website= : Only this tenant (hostname or website id)}
                            {--date= : Bill as of this date (Y-m-d, default today)}
                            {--dry-run : List the due contracts without issuing anything}';

    protected $description = 'Bill the due recurrent contracts that are set to automatic mode (per tenant)';

    public function handle(ContractBillingService $service)
    {
        $date = $this->option('date') ? Carbon::parse($this->option('date'))->format('Y-m-d') : Carbon::today()->format('Y-m-d');
        $dryRun = (bool) $this->option('dry-run');

        $this->info(($dryRun ? '[dry-run] ' : '') . "Recurrent billing as of {$date}");

        foreach (Website::query()->with('hostnames')->get() as $website) {
            $host = optional($website->hostnames->first())->fqdn ?: (string) $website->id;
            if ($this->option('website') && !in_array($this->option('website'), [(string) $website->id, $host], true)) {
                continue;
            }

            app(Environment::class)->tenant($website);
            $this->line("Tenant: {$host}");

            try {
                $due = $service->dueContracts($date, ContractBillingService::MODE_AUTOMATIC, true);
            } catch (Throwable $e) {
                $this->error("  Skipped tenant ({$e->getMessage()})");
                Log::error('contracts:bill-recurrent tenant skipped', ['tenant' => $host, 'error' => $e->getMessage()]);
                continue;
            }

            $issued = $failed = $skipped = 0;

            foreach ($due as $contract) {
                $period = $contract->next_billing_date->format('Y-m-d');
                $label = "{$contract->number_full} (period {$period}, total {$contract->currency_type_id} {$contract->total})";

                if ($dryRun) {
                    $this->line("  would issue: {$label}");
                    continue;
                }

                try {
                    $billing = $service->issue($contract, $period, ContractBillingService::MODE_AUTOMATIC);
                    $issued++;
                    $this->line("  issued: {$label} -> document #{$billing->document_id}");
                } catch (ContractAlreadyBilledException $e) {
                    $skipped++;
                    $this->line("  skipped: {$label} already billed");
                } catch (Throwable $e) {
                    $failed++;
                    $this->error("  failed: {$label}: {$e->getMessage()}");
                    Log::error('contracts:bill-recurrent failed', [
                        'tenant' => $host, 'contract_id' => $contract->id, 'period' => $period, 'error' => $e->getMessage(),
                    ]);
                }
            }

            $this->info($dryRun
                ? "  {$due->count()} due contract(s) in automatic mode."
                : "  issued {$issued}, failed {$failed}, skipped {$skipped}.");
        }

        return 0;
    }
}
