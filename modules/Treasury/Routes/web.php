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

                // Phase 2: Manual movements, ITF & bank fees, voiding
                Route::get('movements', 'MovementController@index')->name('tenant.treasury.movements.index');
                Route::get('movements/tables', 'MovementController@tables');
                Route::get('movements/records', 'MovementController@records');
                Route::post('movements', 'MovementController@store');
                Route::post('movements/preview', 'MovementController@preview');
                Route::post('movements/{id}/void', 'MovementController@void');

                // Phase 2: Transfers (bank<->bank, cash<->bank)
                Route::get('transfers', 'TransferController@index')->name('tenant.treasury.transfers.index');
                Route::get('transfers/tables', 'TransferController@tables');
                Route::get('transfers/records', 'TransferController@records');
                Route::post('transfers', 'TransferController@store');
                Route::post('transfers/{id}/void', 'TransferController@void');

                // Phase 2: SBS exchange rate lookup
                Route::get('exchange-rate/{date}', 'MovementController@exchangeRate');

                // Phase 2: Polymorphic bancarization payment evidence
                Route::get('payment-evidences/tables', 'PaymentEvidenceController@tables');
                Route::get('payment-evidences/{type}/{id}', 'PaymentEvidenceController@show');
                Route::post('payment-evidences/{type}/{id}', 'PaymentEvidenceController@store');

                // Phase 3: Receivables & payables schedule, aging, and alerts
                Route::get('receivables-payables', 'ReceivablePayableController@index')
                    ->name('tenant.treasury.receivables_payables.index');
                Route::get('receivables-payables/tables', 'ReceivablePayableController@tables');
                Route::get('receivables-payables/records', 'ReceivablePayableController@records');
                Route::post('receivables-payables/purchase-fees/{purchase}', 'ReceivablePayableController@storePurchaseFees');
                Route::delete('receivables-payables/purchase-fees/{purchase}', 'ReceivablePayableController@deletePurchaseFees');

                // Phase 4: Detractions (SPOT) control
                Route::get('detractions', 'DetractionController@index')->name('tenant.treasury.detractions.index');
                Route::get('detractions/tables', 'DetractionController@tables');
                Route::get('detractions/records', 'DetractionController@records');
                Route::post('detractions/sync', 'DetractionController@sync');
                Route::post('detractions/release', 'DetractionController@release');
                Route::post('detractions/{id}/deposit', 'DetractionController@deposit');
                Route::post('detractions/{id}/revert', 'DetractionController@revert');

                // Phase 4: Retentions (CRE) and perceptions (CPE): compute, offer the existing emission flow, link
                Route::get('withholdings/tables', 'WithholdingController@tables');
                Route::get('withholdings/records', 'WithholdingController@records');
                Route::get('withholdings/retention-offers/{purchase}', 'WithholdingController@retentionOffers');
                Route::get('withholdings/perception-offers/{document}', 'WithholdingController@perceptionOffers');
                Route::post('withholdings/received-retentions/{document}', 'WithholdingController@storeReceivedRetention');
                Route::post('withholdings/issued-retentions/{payment}', 'WithholdingController@linkRetention');
                Route::post('withholdings/issued-perceptions/{payment}', 'WithholdingController@linkPerception');
                Route::post('withholdings/settle', 'WithholdingController@settle');
                Route::post('withholdings/{id}/void', 'WithholdingController@void');
            });
        });
    });
}
