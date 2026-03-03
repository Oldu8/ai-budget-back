<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\AppUserController;

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
});
