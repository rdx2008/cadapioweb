<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\CourierController;
use App\Http\Controllers\IfoodController;
use App\Http\Controllers\MenuController;
use App\Http\Controllers\OrderBoardController;
use App\Http\Controllers\PlatformController;
use App\Http\Controllers\WhatsappController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => view('welcome'));
Route::middleware('guest')->group(function () {
    Route::view('/login', 'auth.login')->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:6,1');
    Route::view('/entregadores/cadastro', 'auth.register');
    Route::post('/entregadores/cadastro', [AuthController::class, 'register'])->middleware('throttle:5,1');
});
Route::get('/cardapio/{slug}', [MenuController::class, 'menu']);
Route::post('/cardapio/{slug}/review', [MenuController::class, 'review'])->middleware('throttle:30,1');
Route::get('/cardapio/{slug}/review/{draftId}', [MenuController::class, 'reviewPage']);
Route::post('/cardapio/{slug}', [MenuController::class, 'checkout'])->middleware('throttle:20,1');
Route::get('/pedido/{id}', [MenuController::class, 'tracking']);
Route::get('/pedido/{id}/status', [MenuController::class, 'status'])->middleware('throttle:60,1');
Route::get('/media/products/{id}', [CatalogController::class, 'photo']);
Route::post('/webhooks/evolution/{id}', [WhatsappController::class, 'webhook'])->middleware('throttle:120,1');
Route::middleware('auth')->group(function () {
    Route::get('/app', [PlatformController::class, 'home']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::middleware('access:admin')->group(function () {
        Route::get('/admin/ifood', [IfoodController::class, 'admin'])->name('ifood.admin');
        Route::post('/admin/ifood/configure', [IfoodController::class, 'configure'])->name('ifood.configure');
        Route::post('/admin/ifood/test', [IfoodController::class, 'test'])->middleware('throttle:6,1')->name('ifood.test');
        Route::post('/admin/ifood/connect', [IfoodController::class, 'connect'])->middleware('throttle:6,1')->name('ifood.connect');
        Route::post('/admin/ifood/{id}/check', [IfoodController::class, 'check'])->middleware('throttle:6,1')->name('ifood.admin.check');
        Route::post('/admin/ifood/{id}/disconnect', [IfoodController::class, 'disconnect'])->name('ifood.disconnect');
        Route::post('/admin/ifood/{id}/receiving', [IfoodController::class, 'receiving'])->name('ifood.receiving');
        Route::post('/admin/ifood/{id}/actions', [IfoodController::class, 'enableActions'])->name('ifood.actions.enable');
        Route::get('/admin', [PlatformController::class, 'admin']);
        Route::post('/admin/tenants', [PlatformController::class, 'createTenant']);
        Route::post('/admin/tenants/{id}/module', [PlatformController::class, 'module']);
        Route::post('/admin/drivers/{id}', [PlatformController::class, 'approve']);
        Route::post('/admin/evolution', [PlatformController::class, 'evolution']);
    });
    Route::middleware('access:owner')->group(function () {
        Route::get('/integrations/ifood', [IfoodController::class, 'owner'])->name('ifood.owner');
        Route::get('/integrations/ifood/orders/live', [IfoodController::class, 'ordersLive'])->middleware('throttle:60,1')->name('ifood.orders.live');
        Route::get('/integrations/ifood/orders/{id}', [IfoodController::class, 'showOrder'])->name('ifood.orders.show');
        Route::get('/integrations/ifood/orders/{id}/live', [IfoodController::class, 'orderLive'])->middleware('throttle:60,1')->name('ifood.order.live');
        Route::post('/integrations/ifood/orders/{id}/actions', [IfoodController::class, 'action'])->middleware('throttle:20,1')->name('ifood.order.action');
        Route::post('/integrations/ifood/orders/{id}/reasons', [IfoodController::class, 'reasons'])->middleware('throttle:6,1')->name('ifood.order.reasons');
        Route::post('/integrations/ifood/{id}/check', [IfoodController::class, 'check'])->middleware('throttle:6,1')->name('ifood.owner.check');
        Route::get('/dashboard', [PlatformController::class, 'dashboard']);
        Route::get('/orders/live', [OrderBoardController::class, 'live'])->middleware('throttle:60,1');
        Route::get('/orders/{id}', [OrderBoardController::class, 'show']);
        Route::get('/stores/{id}/edit', [CatalogController::class, 'editStore']);
        Route::post('/stores/{id}/edit', [CatalogController::class, 'updateStore']);
        Route::post('/stores/{id}/availability', [CatalogController::class, 'availability'])->name('stores.availability');
        Route::post('/stores/{storeId}/zones', [CatalogController::class, 'zone']);
        Route::get('/products/{id}/edit', [CatalogController::class, 'editProduct']);
        Route::post('/products/{id}/edit', [CatalogController::class, 'updateProduct']);
        Route::post('/products/{id}/groups', [CatalogController::class, 'group']);
        Route::post('/products/{id}/options', [CatalogController::class, 'option']);
        Route::post('/stores', [PlatformController::class, 'createStore']);
        Route::post('/products', [PlatformController::class, 'createProduct']);
        Route::post('/products/{id}/toggle', [PlatformController::class, 'toggleProduct']);
        Route::post('/orders/{id}/status', [PlatformController::class, 'orderStatus']);
        Route::post('/orders/{id}/paid', [PlatformController::class, 'markPaid']);
        Route::post('/orders/{id}/dispatch', [PlatformController::class, 'dispatch']);
        Route::post('/rides/{id}/settle', [PlatformController::class, 'settle']);
        Route::post('/whatsapp/connect', [WhatsappController::class, 'connect']);
    });
    Route::middleware('access:courier')->group(function () {
        Route::get('/courier', [CourierController::class, 'index']);
        Route::post('/courier/presence', [CourierController::class, 'presence']);
        Route::post('/courier/offline', [CourierController::class, 'offline']);
        Route::post('/courier/offers/{id}/accept', [CourierController::class, 'accept']);
        Route::post('/courier/offers/{id}/decline', [CourierController::class, 'decline']);
        Route::post('/courier/rides/{id}', [CourierController::class, 'progress']);
    });
});
