<?php

namespace App\Console\Commands;

use App\CoreFacturalo\Helpers\Sunat\ProductCode;
use App\Models\Tenant\Item;
use Hyn\Tenancy\Models\Website;
use Hyn\Tenancy\Environment;
use Illuminate\Console\Command;

/**
 * Read-only report: items without a valid SUNAT product code (Catalogo 25).
 */
class ItemsMissingProductCode extends Command
{
    protected $signature = 'items:missing-product-code {--website= : Only this tenant (hostname or website id)}';
    protected $description = 'List items without a valid SUNAT product code (read-only, per tenant)';

    public function handle()
    {
        $websites = Website::query()->with('hostnames')->get();
        foreach ($websites as $website) {
            $host = optional($website->hostnames->first())->fqdn;
            if ($this->option('website') && !in_array($this->option('website'), [(string) $website->id, $host], true)) {
                continue;
            }

            app(Environment::class)->tenant($website);
            $bad = 0;
            $total = 0;
            $this->line("Tenant: {$host}");
            Item::query()->orderBy('id')->chunk(500, function ($items) use (&$bad, &$total) {
                foreach ($items as $item) {
                    $total++;
                    if (!ProductCode::exists((string) $item->item_code)) {
                        $bad++;
                        $this->line(sprintf('  #%d  %s  [code: %s]', $item->id, $item->description, $item->item_code ?: '-'));
                    }
                }
            });
            $this->info("  {$bad} of {$total} items without a valid code.");
        }

        return 0;
    }
}
