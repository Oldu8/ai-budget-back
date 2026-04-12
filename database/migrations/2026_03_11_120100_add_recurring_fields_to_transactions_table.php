<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('recurring_expense_id')
                ->nullable()
                ->after('total_amount')
                ->constrained('recurring_expenses')
                ->nullOnDelete();

            $table->dateTime('recurring_run_at')
                ->nullable()
                ->after('confirmed_at');

            $table->index(['recurring_expense_id', 'recurring_run_at']);
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['recurring_expense_id', 'recurring_run_at']);
            $table->dropConstrainedForeignId('recurring_expense_id');
            $table->dropColumn('recurring_run_at');
        });
    }
};
