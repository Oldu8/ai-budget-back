<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->integer('total_amount')->after('date');
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['app_user_id', 'category_id', 'date']);
            $table->dropForeign(['category_id']);
            $table->dropColumn(['category_id', 'amount']);
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('category_id')->after('type')->constrained('categories')->restrictOnDelete();
            $table->integer('amount')->after('category_id');
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('total_amount');
            $table->index(['app_user_id', 'category_id', 'date']);
        });
    }
};
