<?php

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::middleware(['locked.tenant', 'plan.storefront:ecommerce'])->prefix('ecommerce')->group(function() {
   // Route::get('/', 'EcommerceController@index');

    Route::get('/', 'EcommerceController@index')->name('tenant.ecommerce.index');
    Route::get('/category/{category}', 'EcommerceController@category')->name('tenant.ecommerce.category');
    Route::get('item/{id}', 'EcommerceController@item')->name('tenant.ecommerce.item');
    Route::get('items', 'EcommerceController@items')->name('tenant.ecommerce.item.index');
    Route::get('item_partial/{id}', 'EcommerceController@partialItem')->name('item_partial');
    Route::get('detail_cart', 'EcommerceController@detailCart')->name('tenant_detail_cart');
    Route::get('login', 'EcommerceController@showLogin')->name('tenant_ecommerce_login');
    Route::post('logout', 'EcommerceController@logout')->name('tenant_ecommerce_logout');
    Route::get('items_bar', 'EcommerceController@itemsBar');
    Route::post('login', 'EcommerceController@login')->middleware('throttle:ecommerce-login')->name('tenant_ecommerce_login_post');
    Route::post('storeUser', 'EcommerceController@storeUser')->middleware('throttle:ecommerce-register')->name('tenant_ecommerce_store_user');
    Route::post('rating_item', 'EcommerceController@ratingItem')->name('tenant_ecommerce_rating_item');
    Route::get('rating_item/{id}', 'EcommerceController@getRating');




    Route::post('cart_quote', 'EcommerceController@quote')->name('tenant_ecommerce_cart_quote');
    Route::post('culqi', 'CulqiController@payment')->middleware('throttle:ecommerce-checkout')->name('tenant_ecommerce_culqui');
    Route::post('payment_cash', 'EcommerceController@paymentCash')->middleware('throttle:ecommerce-checkout')->name('tenant_ecommerce_payment_cash');
    Route::post('saveDataUser', 'EcommerceController@saveDataUser')->name('tenant_ecommerce_user_data');

    Route::middleware(['auth', 'redirect.module'])->group(function () {
        Route::get('configuration', 'ConfigurationController@index')->name('tenant_ecommerce_configuration');
        Route::post('configuration', 'ConfigurationController@store_configuration');
        Route::post('configuration_culqui', 'ConfigurationController@store_configuration_culqui');
        Route::post('configuration_social', 'ConfigurationController@store_configuration_social');
        Route::post('configuration_tags', 'ConfigurationController@store_configuration_tag');
        Route::get('record', 'ConfigurationController@record');
        Route::post('uploads', 'ConfigurationController@uploadFile');
        Route::post('configuration_logo/remove', 'ConfigurationController@removeLogo');
    });




});
