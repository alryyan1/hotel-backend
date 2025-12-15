<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Auth
Route::post('login', [\App\Http\Controllers\Api\AuthController::class, 'login']);

// Public API (no authentication required)
Route::prefix('public')->group(function () {
    // Room types for public viewing
    Route::get('room-types', [\App\Http\Controllers\Api\RoomTypeController::class, 'index']);
    
    // Availability search
    Route::get('availability', [\App\Http\Controllers\Api\AvailabilityController::class, 'search']);
    
    // Customer operations (for booking)
    Route::get('customers/all', [\App\Http\Controllers\Api\CustomerController::class, 'fetchAll']);
    Route::post('customers', [\App\Http\Controllers\Api\CustomerController::class, 'store']);
    
    // Reservation creation
    Route::post('reservations', [\App\Http\Controllers\Api\ReservationController::class, 'store']);
});

// Protected API
Route::middleware('auth:sanctum')->group(function () {
    Route::get('me', [\App\Http\Controllers\Api\AuthController::class, 'me']);
    Route::post('logout', [\App\Http\Controllers\Api\AuthController::class, 'logout']);

    // Resources
    Route::apiResource('floors', \App\Http\Controllers\Api\FloorController::class);
    Route::apiResource('room-types', \App\Http\Controllers\Api\RoomTypeController::class);
    Route::apiResource('room-statuses', \App\Http\Controllers\Api\RoomStatusController::class);
    Route::apiResource('rooms', \App\Http\Controllers\Api\RoomController::class);
    Route::apiResource('reservations', \App\Http\Controllers\Api\ReservationController::class);
    Route::get('customers/all', [\App\Http\Controllers\Api\CustomerController::class, 'fetchAll']);
    Route::apiResource('customers', \App\Http\Controllers\Api\CustomerController::class);
    Route::apiResource('users', \App\Http\Controllers\Api\UserController::class);

    // Availability
    Route::get('availability', [\App\Http\Controllers\Api\AvailabilityController::class, 'search']);

    // Reservation workflow
    Route::post('reservations/{reservation}/confirm', [\App\Http\Controllers\Api\ReservationController::class, 'confirm']);
    Route::post('reservations/{reservation}/check-in', [\App\Http\Controllers\Api\ReservationController::class, 'checkIn']);
    Route::post('reservations/{reservation}/check-out', [\App\Http\Controllers\Api\ReservationController::class, 'checkOut']);
    Route::post('reservations/{reservation}/cancel', [\App\Http\Controllers\Api\ReservationController::class, 'cancel']);
    Route::get('reservations/export/excel', [\App\Http\Controllers\Api\ReservationController::class, 'exportExcel']);
    Route::get('reservations/{reservation}/invoice/pdf', [\App\Http\Controllers\Api\ReservationController::class, 'exportInvoicePdf']);

    // Hotel settings
    Route::get('settings/hotel', [\App\Http\Controllers\Api\HotelSettingController::class, 'show']);
    Route::post('settings/hotel', [\App\Http\Controllers\Api\HotelSettingController::class, 'update']);

    // Payments
    Route::apiResource('payments', \App\Http\Controllers\Api\PaymentController::class);
    Route::get('customers/{customer}/payments', [\App\Http\Controllers\Api\PaymentController::class, 'getCustomerPayments']);
    Route::get('payments/{payment}/invoice/pdf', [\App\Http\Controllers\Api\PaymentController::class, 'exportInvoicePdf']);

    // Customer Balance
    Route::get('customers/{customer}/balance', [\App\Http\Controllers\Api\CustomerController::class, 'getBalance']);
    
    // Customer PDF Export
    Route::get('customers/{customer}/ledger/pdf', [\App\Http\Controllers\Api\CustomerController::class, 'exportLedgerPdf']);
    
    // Customer Document Management
    Route::post('customers/{customer}/document', [\App\Http\Controllers\Api\CustomerController::class, 'uploadDocument']);
    Route::delete('customers/{customer}/document', [\App\Http\Controllers\Api\CustomerController::class, 'deleteDocument']);
    Route::get('customers/{customer}/document', [\App\Http\Controllers\Api\CustomerController::class, 'downloadDocument']);

    // Costs
    Route::get('costs/export/excel', [\App\Http\Controllers\Api\CostController::class, 'exportExcel']);
    Route::apiResource('costs', \App\Http\Controllers\Api\CostController::class);
    
    // Cost Categories
    Route::apiResource('cost-categories', \App\Http\Controllers\Api\CostCategoryController::class);
    
    // Inventory Categories (must come before inventory routes to avoid route conflicts)
    Route::apiResource('inventory-categories', \App\Http\Controllers\Api\InventoryCategoryController::class);
    Route::get('inventory/categories', [\App\Http\Controllers\Api\InventoryCategoryController::class, 'index']);
    
    // Inventory
    Route::get('inventory/low-stock', [\App\Http\Controllers\Api\InventoryController::class, 'lowStock']);
    Route::get('inventory/{inventory}/history', [\App\Http\Controllers\Api\InventoryController::class, 'history']);
    Route::post('inventory/{inventory}/update-stock', [\App\Http\Controllers\Api\InventoryController::class, 'updateStock']);
    Route::apiResource('inventory', \App\Http\Controllers\Api\InventoryController::class);
    
    // Inventory Orders
    Route::post('inventory-orders/{inventoryOrder}/approve', [\App\Http\Controllers\Api\InventoryOrderController::class, 'approve']);
    Route::apiResource('inventory-orders', \App\Http\Controllers\Api\InventoryOrderController::class);
    
    // Inventory Receipts (must come before inventory routes to avoid route conflicts)
    Route::apiResource('inventory-receipts', \App\Http\Controllers\Api\InventoryReceiptController::class);
    
    // Cleaning Notifications
    Route::get('cleaning-notifications', [\App\Http\Controllers\Api\CleaningNotificationController::class, 'index']);
    Route::get('cleaning-notifications/count', [\App\Http\Controllers\Api\CleaningNotificationController::class, 'count']);
    Route::get('cleaning-notifications/{cleaningNotification}', [\App\Http\Controllers\Api\CleaningNotificationController::class, 'show']);
    Route::post('cleaning-notifications/{cleaningNotification}/complete', [\App\Http\Controllers\Api\CleaningNotificationController::class, 'complete']);
    Route::post('cleaning-notifications/{cleaningNotification}/dismiss', [\App\Http\Controllers\Api\CleaningNotificationController::class, 'dismiss']);
});
