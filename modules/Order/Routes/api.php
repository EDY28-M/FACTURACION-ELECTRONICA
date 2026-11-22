<?php

$hostname = app(Hyn\Tenancy\Contracts\CurrentHostname::class);
if ($hostname) {
    Route::domain($hostname->fqdn)->group(function() {

        // Pedidos (order notes) belong to the Ventas module.
        Route::middleware(['auth:api', 'locked.tenant', 'plan.module:documents'])->group(function() {

            Route::post('order-note/email', 'Api\OrderNoteController@email');

            Route::prefix('order-notes')->group(function () {
                Route::post('', 'OrderNoteController@store');
                Route::get('lists', 'Api\OrderNoteController@lists');
            });

        });

    });
}
