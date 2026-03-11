<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\AppUserController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CategoryVisibilityController;
use App\Http\Controllers\ManualTransactionController;
use App\Http\Controllers\ScanController;
use App\Http\Controllers\ExpenseExportController;
use App\Http\Controllers\TransactionSummaryController;
use App\Http\Controllers\RecurringExpenseController;

Route::middleware('throttle:5,1')->group(function () {
    Route::post('/auth/register', [AuthController::class, 'register']);
    Route::post('/auth/login', [AuthController::class, 'login']);
    Route::post('/auth/refresh', [AuthController::class, 'refresh']);
});
Route::post('/auth/logout', [AuthController::class, 'logout']);

Route::middleware('supabase.auth')->group(function () {
    Route::get('/me', [AppUserController::class, 'show']);
    Route::put('/me', [AppUserController::class, 'update']);
    Route::delete('/me', [AppUserController::class, 'destroy']);

    Route::get('/me/settings', [AppUserController::class, 'showSettings']);
    Route::post('/me/settings', [AppUserController::class, 'createSettings']);
    Route::put('/me/settings', [AppUserController::class, 'updateSettings']);

    Route::get('/categories', [CategoryController::class, 'index']);

    Route::get('/funnel/visibility-questions', [CategoryVisibilityController::class, 'funnelQuestions']);
    Route::get('/visibility-tags', [CategoryVisibilityController::class, 'visibilityTags']);
    Route::patch('/me/category-visibility', [CategoryVisibilityController::class, 'update']);

    Route::post('/transactions/scan', [ScanController::class, 'scan']);
    Route::post('/transactions/{transaction}/confirm', [ScanController::class, 'confirm']);

    Route::get('/transactions/summary', [TransactionSummaryController::class, 'index']);
    Route::get('/transactions/summary/{category}', [TransactionSummaryController::class, 'show']);
    Route::get('/transactions/export', [ExpenseExportController::class, 'export']);

    Route::get('/transactions', [ManualTransactionController::class, 'index']);
    Route::post('/transactions', [ManualTransactionController::class, 'store']);
    Route::get('/transactions/{transaction}', [ManualTransactionController::class, 'show']);
    Route::put('/transactions/{transaction}', [ManualTransactionController::class, 'update']);
    Route::delete('/transactions/{transaction}', [ManualTransactionController::class, 'destroy']);

    Route::get('/recurring-expenses', [RecurringExpenseController::class, 'index']);
    Route::post('/recurring-expenses', [RecurringExpenseController::class, 'store']);
    Route::get('/recurring-expenses/{id}', [RecurringExpenseController::class, 'show']);
    Route::put('/recurring-expenses/{id}', [RecurringExpenseController::class, 'update']);
    Route::delete('/recurring-expenses/{id}', [RecurringExpenseController::class, 'destroy']);
    Route::patch('/recurring-expenses/{id}/toggle', [RecurringExpenseController::class, 'toggle']);
});
