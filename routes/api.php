<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\AppUserController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\ManualTransactionController;

Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/auth/refresh', [AuthController::class, 'refresh']);
Route::post('/auth/logout', [AuthController::class, 'logout']);

Route::middleware('supabase.auth')->group(function () {
    Route::get('/me', [AppUserController::class, 'show']);
    Route::put('/me', [AppUserController::class, 'update']);
    Route::delete('/me', [AppUserController::class, 'destroy']);

    Route::get('/me/settings', [AppUserController::class, 'showSettings']);
    Route::post('/me/settings', [AppUserController::class, 'createSettings']);
    Route::put('/me/settings', [AppUserController::class, 'updateSettings']);

    Route::get('/categories', [CategoryController::class, 'index']);

    Route::get('/transactions', [ManualTransactionController::class, 'index']);
    Route::post('/transactions', [ManualTransactionController::class, 'store']);
    Route::get('/transactions/{transaction}', [ManualTransactionController::class, 'show']);
    Route::put('/transactions/{transaction}', [ManualTransactionController::class, 'update']);
    Route::delete('/transactions/{transaction}', [ManualTransactionController::class, 'destroy']);
});
