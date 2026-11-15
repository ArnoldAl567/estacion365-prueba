<?php

use App\Http\Controllers\CatalogController;
use App\Http\Controllers\DemandReportController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\OrderModifierController;
use App\Http\Controllers\SalonLayoutController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\StaffController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json(['service' => 'La Estación 365 · Pedidos y cocina']));

Route::prefix('local-api')->group(function () {
    Route::get('/session', [SessionController::class, 'show']);
    Route::post('/setup', [SessionController::class, 'setup'])->middleware('throttle:5,1');
    Route::post('/login', [SessionController::class, 'login'])->middleware('throttle:10,1');
    Route::post('/logout', [SessionController::class, 'logout']);
    Route::get('/catalog', [OrderController::class, 'catalog']);
    Route::get('/images/{filename}', [CatalogController::class, 'image'])->where('filename', '[0-9a-f-]{36}\.webp');
    Route::post('/orders', [OrderController::class, 'store'])->middleware('throttle:60,1');
    Route::get('/tracking/{trackingId}', [OrderController::class, 'track'])->whereUuid('trackingId')->middleware('throttle:90,1');

    Route::middleware('staff')->group(function () {
        Route::get('/orders', [OrderController::class, 'index']);
        Route::get('/orders/{order}', [OrderController::class, 'show'])->whereNumber('order');
        Route::patch('/orders/{order}', [OrderController::class, 'update'])->whereNumber('order');
    });
    Route::middleware('staff:admin')->group(function () {
        Route::get('/salon-layout', [SalonLayoutController::class, 'show']);
        Route::put('/salon-layout', [SalonLayoutController::class, 'update']);
        Route::post('/staff-orders', [OrderController::class, 'storeFromStaff']);
        Route::patch('/staff-orders/{order}', [OrderController::class, 'editFromStaff'])->whereNumber('order');
        Route::get('/order-modifiers', [OrderModifierController::class, 'index']);
        Route::post('/order-modifiers', [OrderModifierController::class, 'store']);
        Route::delete('/order-modifiers/{id}', [OrderModifierController::class, 'destroy'])->whereNumber('id');
        Route::get('/reports/demand', DemandReportController::class);
        Route::post('/catalog/items', [CatalogController::class, 'store']);
        Route::patch('/catalog/items/{itemKey}', [CatalogController::class, 'update'])->where('itemKey', '[A-Za-z0-9:_-]+');
        Route::put('/catalog/prices', [CatalogController::class, 'prices']);
        Route::post('/catalog/images', [CatalogController::class, 'upload'])->middleware('throttle:30,1');
        Route::get('/users', [StaffController::class, 'index']);
        Route::post('/users', [StaffController::class, 'store']);
        Route::patch('/users/{user}', [StaffController::class, 'update'])->whereNumber('user');
    });
});
