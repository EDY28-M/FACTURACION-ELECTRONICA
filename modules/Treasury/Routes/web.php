<?php

use Illuminate\Support\Facades\Route;

$hostname = app(\Hyn\Tenancy\Contracts\CurrentHostname::class);

if ($hostname) {
    Route::domain($hostname->fqdn)->group(function () {
        Route::middleware(['auth', 'redirect.module', 'locked.tenant'])->group(function () {
            Route::prefix('treasury')->group(function () {
                // Phase 1: Balance summary (Resumen)
                Route::get('', 'DashboardController@index')->name('tenant.treasury.dashboard.index');
                Route::get('summary', 'DashboardController@index')->name('tenant.treasury.summary.index');
                Route::get('summary/tables', 'DashboardController@tables');
                Route::get('summary/records', 'DashboardController@records');

                // Phase 1: Accounts (Cuentas bancarias y cajas)
                Route::get('accounts', 'AccountController@index')->name('tenant.treasury.accounts.index');
                Route::get('accounts/tables', 'AccountController@tables');
                Route::get('accounts/records', 'AccountController@records');
                Route::get('accounts/record/{id}', 'AccountController@record');
                Route::post('accounts', 'AccountController@store');
            });
        });
    });
}
