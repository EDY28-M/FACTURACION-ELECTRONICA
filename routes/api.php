<?php

$hostname = app(Hyn\Tenancy\Contracts\CurrentHostname::class);
if ($hostname) {
    Route::domain($hostname->fqdn)->group(function () {

        Route::post('login', 'Tenant\Api\MobileController@login');

        Route::middleware(['auth:api', 'locked.tenant'])->group(function () {
            // Lookups shared by every flow: open to any API user of the company.
            Route::post('person', 'Tenant\Api\MobileController@person');
            Route::get('services/ruc/{number}', 'Tenant\Api\ServiceController@ruc');
            Route::get('services/dni/{number}', 'Tenant\Api\ServiceController@dni');

            // Sales (facturas, boletas, notas de venta): the plan must include Ventas or POS.
            Route::middleware('plan.module:documents,pos')->group(function () {
                //MOBILE
                Route::get('document/series', 'Tenant\Api\MobileController@getSeries');
                Route::get('document/tables', 'Tenant\Api\MobileController@tables');
                Route::get('document/customers', 'Tenant\Api\MobileController@customers');
                Route::post('document/email', 'Tenant\Api\MobileController@document_email');
                Route::post('sale-note', 'Tenant\Api\SaleNoteController@store');
                Route::get('sale-note/series', 'Tenant\Api\SaleNoteController@series');
                Route::get('sale-note/lists', 'Tenant\Api\SaleNoteController@lists');
                Route::get('document/search-items', 'Tenant\Api\MobileController@searchItems');
                Route::get('document/search-customers', 'Tenant\Api\MobileController@searchCustomers');
                Route::post('sale-note/email', 'Tenant\Api\SaleNoteController@email');

                Route::get('report', 'Tenant\Api\MobileController@report');

                Route::post('documents', 'Tenant\Api\DocumentController@store');
                Route::get('documents/lists', 'Tenant\Api\DocumentController@lists');
                Route::get('documents/lists/{startDate}/{endDate}', 'Tenant\Api\DocumentController@lists');
                Route::post('documents/updatedocumentstatus', 'Tenant\Api\DocumentController@updatestatus');
                Route::post('summaries', 'Tenant\Api\SummaryController@store');
                Route::post('voided', 'Tenant\Api\VoidedController@store');
                Route::post('documents/send', 'Tenant\Api\DocumentController@send');
                Route::post('summaries/status', 'Tenant\Api\SummaryController@status');
                Route::post('voided/status', 'Tenant\Api\VoidedController@status');
                Route::post('services/consult_cdr_status', 'Tenant\Api\ServiceController@consultCdrStatus');

                Route::post('documents_server', 'Tenant\Api\DocumentController@storeServer');
                Route::get('document_check_server/{external_id}', 'Tenant\Api\DocumentController@documentCheckServer');
                Route::get('documents/search/customers', 'Tenant\DocumentController@searchCustomers');
                Route::post('documents/status', 'Tenant\Api\ServiceController@documentStatus');
                Route::get('sendserver/{document_id}/{query?}', 'Tenant\DocumentController@sendServer');
            });

            // The mobile sales app creates and edits products while selling.
            Route::middleware('plan.module:items,documents,pos')->group(function () {
                Route::post('item', 'Tenant\Api\MobileController@item');
                Route::post('items/{id}/update', 'Tenant\Api\MobileController@updateItem');
            });

            // Retenciones, percepciones y guías: Documentos avanzados.
            Route::middleware('plan.module:advanced')->group(function () {
                Route::post('retentions', 'Tenant\Api\RetentionController@store');
                Route::post('perceptions', 'Tenant\Api\PerceptionController@store');
                Route::post('dispatches', 'Tenant\Api\DispatchController@store');
                Route::post('configurations/generateDispatch', 'Tenant\ConfigurationController@generateDispatch');
            });
        });
    });
} else {
    Route::domain(config('app.url_base'))->group(function () {

        Route::middleware(['web', 'auth:admin'])
            ->withoutMiddleware(\App\Http\Middleware\LockedAdmin::class)
            ->group(function () {
                Route::post('reseller/detail', 'System\Api\ResellerController@resellerDetail');
                Route::post('reseller/lockedAdmin', 'System\Api\ResellerController@lockedAdmin');
            });

    });

}
