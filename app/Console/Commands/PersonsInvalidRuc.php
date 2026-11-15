<?php

namespace App\Console\Commands;

use App\CoreFacturalo\Helpers\Sunat\RucValidator;
use App\Models\Tenant\Person;
use Hyn\Tenancy\Environment;
use Hyn\Tenancy\Models\Hostname;
use Hyn\Tenancy\Models\Website;
use Illuminate\Console\Command;

/**
 * Read-only report: persons (customers/suppliers) whose RUC fails the Modulo 11 check, per tenant.
 */
class PersonsInvalidRuc extends Command
{
    protected $signature = 'persons:invalid-ruc {--tenant= : Only this tenant hostname (FQDN)}';

    protected $description = 'List customers/suppliers with a malformed RUC (read-only, per tenant)';

    public function handle()
    {
        $total = 0;
        $filter = $this->option('tenant');

        foreach (Website::query()->get() as $website) {
            $hostname = Hostname::query()->where('website_id', $website->id)->first();
            $fqdn = $hostname ? $hostname->fqdn : $website->uuid;

            if ($filter && $filter !== $fqdn) {
                continue;
            }

            app(Environment::class)->tenant($website);

            $rows = Person::query()
                ->where('identity_document_type_id', RucValidator::IDENTITY_DOCUMENT_TYPE_ID)
                ->get(['id', 'type', 'number', 'name'])
                ->reject(function ($person) {
                    return RucValidator::isValid($person->number);
                });

            $this->info("{$fqdn}: {$rows->count()} person(s) with an invalid RUC");

            if ($rows->isNotEmpty()) {
                $this->table(
                    ['id', 'type', 'number', 'name'],
                    $rows->map(function ($p) {
                        return [$p->id, $p->type, $p->number, $p->name];
                    })->all()
                );
            }

            $total += $rows->count();
        }

        $this->info("Total: {$total}");

        return 0;
    }
}
