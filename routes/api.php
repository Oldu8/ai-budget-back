<?php

use App\Http\Controllers\AnalyticsDashboardController;
use App\Http\Controllers\AppUserController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CategoryVisibilityController;
use App\Http\Controllers\ExpenseExportController;
use App\Http\Controllers\ManualTransactionController;
use App\Http\Controllers\PlanController;
use App\Http\Controllers\RecurringExpenseController;
use App\Http\Controllers\RevenueCatController;
use App\Http\Controllers\ScanController;
use App\Http\Controllers\StripeController;
use App\Http\Controllers\TransactionSummaryController;
use App\Http\Controllers\VoiceTransactionController;
use App\Http\Middleware\EnsureApiKey;
use Illuminate\Support\Facades\Route;

// Healthcheck for Railway — no session, no DB, always 200
Route::get('/health', fn () => response()->json(['status' => 'ok']));

// Stripe webhook — no JWT, no API key; authenticated solely by Stripe signature
Route::post('/stripe/webhook', [StripeController::class, 'webhook'])
    ->withoutMiddleware(EnsureApiKey::class);

// RevenueCat webhook — no JWT, no API key; authenticated solely by the shared Authorization secret
Route::post('/webhooks/revenuecat', [RevenueCatController::class, 'webhook'])
    ->withoutMiddleware(EnsureApiKey::class);

Route::middleware('throttle:5,1')->group(function () {
    Route::post('/auth/register', [AuthController::class, 'register']);
    Route::post('/auth/login', [AuthController::class, 'login']);
    Route::post('/auth/social', [AuthController::class, 'social']);
    Route::post('/auth/refresh', [AuthController::class, 'refresh']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
});

Route::middleware('auth.app')->group(function () {
    Route::get('/me', [AppUserController::class, 'show']);
    Route::put('/me', [AppUserController::class, 'update']);
    Route::delete('/me', [AppUserController::class, 'destroy']);
    Route::put('/me/password', [AppUserController::class, 'changePassword']);

    Route::get('/me/settings', [AppUserController::class, 'showSettings']);
    Route::post('/me/settings', [AppUserController::class, 'createSettings']);
    Route::put('/me/settings', [AppUserController::class, 'updateSettings']);

    Route::get('/categories', [CategoryController::class, 'index']);
    Route::get('/plans', [PlanController::class, 'index']);

    Route::get('/funnel/visibility-questions', [CategoryVisibilityController::class, 'funnelQuestions']);
    Route::get('/visibility-tags', [CategoryVisibilityController::class, 'visibilityTags']);
    Route::patch('/me/category-visibility', [CategoryVisibilityController::class, 'update']);

    Route::post('/transactions/scan', [ScanController::class, 'scan']);
    Route::post('/transactions/voice', [VoiceTransactionController::class, 'store'])
        ->middleware('feature.access:voice_expense');
    Route::put('/transactions/{transaction}/update', [ScanController::class, 'updateReceipt']);
    Route::get('/transactions/{transaction}/image', [ScanController::class, 'image']);

    Route::get('/transactions/summary', [TransactionSummaryController::class, 'index']);
    Route::get('/transactions/summary/{category}', [TransactionSummaryController::class, 'show']);
    Route::get('/transactions/export', [ExpenseExportController::class, 'export'])
        ->middleware('feature.access:csv_export');

    Route::get('/analytics/dashboard', AnalyticsDashboardController::class)
        ->middleware('feature.access:analytics_dashboard');

    Route::get('/transactions', [ManualTransactionController::class, 'index']);
    Route::post('/transactions', [ManualTransactionController::class, 'store']);
    Route::get('/transactions/{transaction}', [ManualTransactionController::class, 'show']);
    Route::put('/transactions/{transaction}', [ManualTransactionController::class, 'update']);
    Route::delete('/transactions/{transaction}', [ManualTransactionController::class, 'destroy']);

    Route::middleware('feature.access:recurring_expenses')->group(function () {
        Route::get('/recurring-expenses', [RecurringExpenseController::class, 'index']);
        Route::post('/recurring-expenses', [RecurringExpenseController::class, 'store']);
        Route::get('/recurring-expenses/{id}', [RecurringExpenseController::class, 'show']);
        Route::put('/recurring-expenses/{id}', [RecurringExpenseController::class, 'update']);
        Route::delete('/recurring-expenses/{id}', [RecurringExpenseController::class, 'destroy']);
        Route::patch('/recurring-expenses/{id}/toggle', [RecurringExpenseController::class, 'toggle']);
    });

    Route::post('/stripe/checkout', [StripeController::class, 'checkout']);
    Route::post('/stripe/portal', [StripeController::class, 'portal']);
});
