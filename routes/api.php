<?php

declare(strict_types=1);

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

// Public endpoints (CA-01.3, api-contract.md §3.1)
Route::get('/health', [AuthController::class, 'health']);
Route::post('/auth/login', [AuthController::class, 'login']);

// Authenticated endpoints (CA-01.3)
Route::middleware('jwt.auth')->group(function (): void {
    Route::get('/categories', [CategoryController::class, 'index']);
});

// Admin-only endpoints (api-contract.md §3.2)
Route::middleware('jwt.auth:admin')->group(function (): void {
    Route::post('/users', [UserController::class, 'store']);
});
