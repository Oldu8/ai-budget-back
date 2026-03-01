<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use App\Http\Controllers\TransactionController;

Route::middleware('supabase.auth')->group(function () {
    Route::get('/me', function (Request $request) {
        return $request->user(); // это AppUser из app_users
    });
    // и т.д.
});
