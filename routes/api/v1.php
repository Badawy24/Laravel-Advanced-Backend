<?php

declare(strict_types=1);

use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\V1\OrderController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->name('auth.')->group(function (): void {
    Route::post('register', [AuthController::class, 'register'])->name('register');
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login')->name('login');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('profile', [AuthController::class, 'profile'])->name('profile');
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');
        Route::post('logout-all', [AuthController::class, 'logoutAll'])->name('logout-all');
        Route::put('password', [AuthController::class, 'changePassword'])->name('password');
    });
});

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('orders', [OrderController::class, 'store'])->name('orders.store');
});
