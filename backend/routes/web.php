<?php

use App\Http\Controllers\CatalogController;
use App\Http\Controllers\CashController;
use App\Http\Controllers\DemandReportController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\OrderModifierController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\OperationsReportController;
use App\Http\Controllers\SalesReportController;
use App\Http\Controllers\OperationsSettingsController;
use App\Http\Controllers\SalonLayoutController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\StaffController;
use App\Http\Controllers\RecordRemovalController;
use App\Http\Controllers\InventoryIncidentController;
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

    Route::middleware('staff:admin,kitchen,waiter')->group(function () {
        Route::get('/orders', [OrderController::class, 'index']);
        Route::get('/orders/{order}', [OrderController::class, 'show'])->whereNumber('order');
        Route::patch('/orders/{order}', [OrderController::class, 'update'])->whereNumber('order');
    });
    Route::patch('/orders/{order}/tickets/{center}', [OrderController::class, 'advanceTicket'])
        ->middleware('staff:admin,kitchen')->whereNumber('order');
    Route::middleware('staff:admin,waiter')->group(function () {
        Route::get('/salon-layout', [SalonLayoutController::class, 'show']);
        Route::post('/staff-orders', [OrderController::class, 'storeFromStaff']);
        Route::patch('/staff-orders/{order}', [OrderController::class, 'editFromStaff'])->whereNumber('order');
        Route::get('/order-modifiers', [OrderModifierController::class, 'index']);
        Route::post('/order-modifiers', [OrderModifierController::class, 'store']);
        Route::patch('/order-modifiers/{id}', [OrderModifierController::class, 'update'])->whereNumber('id');
        Route::delete('/order-modifiers/{id}', [OrderModifierController::class, 'destroy'])->whereNumber('id');
    });
    Route::middleware('staff:admin,cashier')->group(function () {
        Route::get('/cash/payments', [\App\Http\Controllers\CashPaymentHistoryController::class, 'index']);
        Route::get('/cash/payments/{id}', [\App\Http\Controllers\CashPaymentHistoryController::class, 'show'])->whereNumber('id');
        Route::get('/cash', [CashController::class, 'show']);
        Route::post('/cash/open', [CashController::class, 'open']);
        Route::post('/cash/close', [CashController::class, 'close']);
        Route::post('/cash/orders/{order}/payment', [CashController::class, 'payment'])->whereNumber('order');
        Route::post('/cash/orders/{order}/close-table', [CashController::class, 'closeTable'])->whereNumber('order');
    });
    Route::middleware('staff:admin,stock')->group(function () {
        Route::get('/inventory', [InventoryController::class, 'index']);
        Route::get('/inventory/incidents', [InventoryIncidentController::class, 'index']);
        Route::post('/inventory/incidents', [InventoryIncidentController::class, 'store']);
        Route::post('/inventory/incidents/{id}/apply', [InventoryIncidentController::class, 'apply'])->whereNumber('id');
        Route::post('/inventory/incidents/{id}/cancel', [InventoryIncidentController::class, 'cancel'])->whereNumber('id');
        Route::post('/inventory/categories', [InventoryController::class, 'saveCategory']);
        Route::post('/inventory/categories/{id}/delete', [InventoryController::class, 'deleteCategory'])->whereNumber('id');
        Route::get('/inventory/availability', [InventoryController::class, 'availability']);
        Route::get('/inventory/purchases/{id}', [InventoryController::class, 'purchaseDetail'])->whereNumber('id');
        Route::post('/inventory/purchases/{id}/cancel', [InventoryController::class, 'cancelPurchase'])->whereNumber('id');
        Route::get('/inventory/batches/{id}', [InventoryController::class, 'batchDetail'])->whereNumber('id');
        Route::post('/inventory/units/{id}/delete', [InventoryController::class, 'deleteUnit'])->whereNumber('id');
        Route::post('/inventory/remove/{kind}/{id}', [RecordRemovalController::class, 'inventory'])
            ->whereIn('kind', ['items', 'suppliers', 'recipes', 'purchases', 'batches', 'orders'])->whereNumber('id');
        Route::post('/inventory/master/{kind}', [InventoryController::class, 'master'])->whereIn('kind', ['units', 'suppliers', 'items']);
        Route::post('/inventory/purchases', [InventoryController::class, 'purchase']);
        Route::post('/inventory/movements', [InventoryController::class, 'movement']);
        Route::post('/inventory/recipes', [InventoryController::class, 'recipe']);
        Route::post('/inventory/batches', [InventoryController::class, 'batch']);
        Route::post('/inventory/plans', [InventoryController::class, 'plan']);
        Route::post('/inventory/orders/{order}/consume', [InventoryController::class, 'consume'])->whereNumber('order');
    });
    Route::middleware('staff:admin')->group(function () {
        Route::post('/cash/templates', [CashController::class, 'saveTemplate']);
        Route::post('/cash/templates/{id}/delete', [CashController::class, 'deleteTemplate'])->whereNumber('id');
        Route::put('/salon-layout', [SalonLayoutController::class, 'update']);
        Route::get('/reports/demand', DemandReportController::class);
        Route::get('/reports/operations', [OperationsReportController::class, 'index']);
        Route::get('/reports/sales', [SalesReportController::class, 'index']);
        Route::get('/operations-settings', [OperationsSettingsController::class, 'show']);
        Route::post('/operations-settings/remove/{kind}/{key}', [RecordRemovalController::class, 'settings'])
            ->whereIn('kind', ['centers', 'methods', 'routes', 'printers', 'categories']);
        Route::post('/operations-settings/centers', [OperationsSettingsController::class, 'center']);
        Route::post('/operations-settings/printers', [OperationsSettingsController::class, 'printer']);
        Route::post('/operations-settings/routes', [OperationsSettingsController::class, 'route']);
        Route::post('/operations-settings/categories', [OperationsSettingsController::class, 'category']);
        Route::post('/operations-settings/methods', [OperationsSettingsController::class, 'paymentMethod']);
        Route::post('/catalog/items', [CatalogController::class, 'store']);
        Route::patch('/catalog/items/{itemKey}', [CatalogController::class, 'update'])->where('itemKey', '[A-Za-z0-9:_-]+');
        Route::put('/catalog/prices', [CatalogController::class, 'prices']);
        Route::post('/catalog/images', [CatalogController::class, 'upload'])->middleware('throttle:30,1');
        Route::get('/users', [StaffController::class, 'index']);
        Route::post('/users', [StaffController::class, 'store']);
        Route::patch('/users/{user}', [StaffController::class, 'update'])->whereNumber('user');
    });
});
