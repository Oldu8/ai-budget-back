<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->index(['app_user_id', 'date', 'type']);
        });

        Schema::table('transaction_items', function (Blueprint $table) {
            $table->index('category_id');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['app_user_id', 'date', 'type']);
        });

        Schema::table('transaction_items', function (Blueprint $table) {
            $table->dropIndex(['category_id']);
        });
    }
};
