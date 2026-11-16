<?php

namespace Hyn\Tenancy\Commands;

use Hyn\Tenancy\Database\Connection;
use Hyn\Tenancy\Models\Website;
use Illuminate\Console\Command;

class MigrateCommand extends Command
{
    protected $signature = 'tenancy:migrate {--website_id=*} {--path=} {--realpath} {--force}';
    protected $description = 'Run migrations for all or specific tenant websites.';

    public function handle(Connection $connection)
    {
        $query = Website::query();
        $websiteIds = $this->option('website_id');
        if (!empty($websiteIds)) {
            $query->whereIn('id', (array) $websiteIds);
        }

        $websites = $query->get();
        $path = $this->option('path') ?: config('tenancy.db.tenant-migrations-path');

        foreach ($websites as $website) {
            $this->info("Migrating tenant website: {$website->uuid}");
            $connection->migrate($website, $path);
        }

        return 0;
    }
}
