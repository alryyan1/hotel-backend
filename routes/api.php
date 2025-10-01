<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Auth
Route::post('login', [\App\Http\Controllers\Api\AuthController::class, 'login']);

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
    Route::apiResource('customers', \App\Http\Controllers\Api\CustomerController::class);

    // Availability
    Route::get('availability', [\App\Http\Controllers\Api\AvailabilityController::class, 'search']);

    // Reservation workflow
    Route::post('reservations/{reservation}/confirm', [\App\Http\Controllers\Api\ReservationController::class, 'confirm']);
    Route::post('reservations/{reservation}/check-in', [\App\Http\Controllers\Api\ReservationController::class, 'checkIn']);
    Route::post('reservations/{reservation}/check-out', [\App\Http\Controllers\Api\ReservationController::class, 'checkOut']);
    Route::post('reservations/{reservation}/cancel', [\App\Http\Controllers\Api\ReservationController::class, 'cancel']);

    // Hotel settings
    Route::get('settings/hotel', [\App\Http\Controllers\Api\HotelSettingController::class, 'show']);
    Route::post('settings/hotel', [\App\Http\Controllers\Api\HotelSettingController::class, 'update']);
});
