<?php

use App\Http\Controllers\Api\V1\CourierAuthController;
use App\Http\Controllers\Api\V1\CourierController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::post('/courier/login', [CourierAuthController::class, 'login'])->middleware('throttle:6,1,api-courier-login:')->name('courier.login');

    Route::middleware(['auth:sanctum', 'access:courier'])->prefix('courier')->name('courier.')->group(function () {
        Route::post('/logout', [CourierAuthController::class, 'logout'])->name('logout');
        Route::get('/me', [CourierAuthController::class, 'me'])->name('me');
        Route::put('/devices', [CourierAuthController::class, 'device'])->middleware('throttle:10,1,api-devices:')->name('devices');

        Route::get('/stores', [CourierController::class, 'stores'])->middleware('throttle:30,1,api-stores:')->name('stores');
        Route::get('/board', [CourierController::class, 'board'])->middleware('throttle:60,1,api-board:')->name('board');
        Route::post('/heartbeat', [CourierController::class, 'heartbeat'])->middleware('throttle:6,1,api-heartbeat:')->name('heartbeat');
        Route::post('/presence', [CourierController::class, 'presence'])->middleware('throttle:10,1,api-presence:')->name('presence');
        Route::post('/offline', [CourierController::class, 'offline'])->middleware('throttle:10,1,api-offline:')->name('offline');
        Route::post('/offers/{id}/accept', [CourierController::class, 'accept'])->whereNumber('id')->middleware('throttle:20,1,api-offers-accept:')->name('offers.accept');
        Route::post('/offers/{id}/decline', [CourierController::class, 'decline'])->whereNumber('id')->middleware('throttle:20,1,api-offers-decline:')->name('offers.decline');
        Route::get('/rides/{id}', [CourierController::class, 'ride'])->whereNumber('id')->middleware('throttle:60,1,api-rides-show:')->name('rides.show');
        Route::post('/rides/{id}/progress', [CourierController::class, 'progress'])->whereNumber('id')->middleware('throttle:delivery-progress')->name('rides.progress');
        Route::post('/rides/{id}/return', [CourierController::class, 'returnToStore'])->whereNumber('id')->middleware('throttle:10,1,api-rides-return:')->name('rides.return');
        Route::post('/rides/{id}/incidents', [CourierController::class, 'incident'])->whereNumber('id')->middleware('throttle:10,1,api-rides-incidents:')->name('rides.incidents');
        Route::get('/statement', [CourierController::class, 'statement'])->middleware('throttle:30,1,api-statement:')->name('statement');
    });
});
