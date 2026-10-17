<?php

declare(strict_types=1);

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BookController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\LoanController;
use App\Http\Controllers\MemberController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

// 3.1 Autenticación y salud (público)
Route::get('/health', [AuthController::class, 'health']);
Route::post('/auth/login', [AuthController::class, 'login']);

// Endpoints autenticados para admin y librarian (CA-01.3)
Route::middleware('jwt.auth:admin,librarian')->group(function (): void {
    // 3.3 Categorías
    Route::get('/categories', [CategoryController::class, 'index']);

    // 3.4 Libros (lectura y binario de portada)
    Route::get('/books', [BookController::class, 'index']);
    Route::get('/books/{id}', [BookController::class, 'show']);
    Route::get('/books/{id}/cover', [BookController::class, 'showCover']);

    // 3.5 Socios
    Route::get('/members', [MemberController::class, 'index']);
    Route::get('/members/{id}', [MemberController::class, 'show']);
    Route::post('/members', [MemberController::class, 'store']);

    // 3.6 Préstamos (/loans/overdue registrado antes de /loans/{id})
    Route::post('/loans', [LoanController::class, 'store']);
    Route::get('/loans', [LoanController::class, 'index']);
    Route::get('/loans/overdue', [LoanController::class, 'overdue']);
    Route::get('/loans/{id}', [LoanController::class, 'show']);
    Route::post('/loans/{loan_id}/items/{item_id}/return', [LoanController::class, 'returnItem']);
});

// Endpoints exclusivos para admin (api-contract.md §3.2, §3.4, §3.7)
Route::middleware('jwt.auth:admin')->group(function (): void {
    // 3.2 Usuarios
    Route::post('/users', [UserController::class, 'store']);

    // 3.4 Libros (escritura, baja lógica y gestión de portada)
    Route::post('/books', [BookController::class, 'store']);
    Route::put('/books/{id}', [BookController::class, 'update']);
    Route::delete('/books/{id}', [BookController::class, 'destroy']);
    Route::put('/books/{id}/cover', [BookController::class, 'uploadCover']);
    Route::delete('/books/{id}/cover', [BookController::class, 'deleteCover']);

    // 3.7 Reporte
    Route::get('/reports/most-borrowed', [ReportController::class, 'mostBorrowed']);
});
