<?php

namespace App\Providers;

use App\Models\Tenant\Document;
use App\Observers\DocumentObserver;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
	public function boot()
	{
		if (config('tenant.force_https')) {
			URL::forceScheme('https');
		}
		Document::observe(DocumentObserver::class);
	}

	public function register()
	{
		if (class_exists(\DatabaseSeeder::class) && !class_exists(\Database\Seeders\DatabaseSeeder::class, false)) {
			class_alias(\DatabaseSeeder::class, \Database\Seeders\DatabaseSeeder::class);
		}
		if (class_exists(\TenancyDatabaseSeeder::class) && !class_exists(\Database\Seeders\TenancyDatabaseSeeder::class, false)) {
			class_alias(\TenancyDatabaseSeeder::class, \Database\Seeders\TenancyDatabaseSeeder::class);
		}
	}
}
