<?php

use Illuminate\Support\Facades\Route;
use Webkul\Security\Http\Controllers\API\V1\AuthController;
use Webkul\Security\Http\Controllers\API\V1\DeviceController;

// Authentication routes (public)
Route::prefix('admin/api/v1')->group(function () {
    Route::post('login', [AuthController::class, 'login']);
});

// Protected routes (require authentication)
Route::prefix('admin/api/v1')->middleware(['auth:sanctum'])->group(function () {
    Route::post('logout', [AuthController::class, 'logout']);

    Route::get('devices', [DeviceController::class, 'index']);
    Route::post('devices', [DeviceController::class, 'store']);
    Route::delete('devices/{device}', [DeviceController::class, 'destroy']);
});
