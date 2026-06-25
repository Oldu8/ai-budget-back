<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // Currency the stored amounts (total_amount, item amounts) are kept in.
            // Captured per transaction so changing the user's accounting currency
            // later does not retro-convert historical records.
            $table->string('currency', 3)->nullable()->after('total_amount');

            // What was actually on the receipt / spoken by the user.
            $table->string('original_currency', 3)->nullable()->after('currency');
            $table->integer('original_total_amount')->nullable()->after('original_currency');

            // Frozen conversion metadata: target_amount = round(original_amount * exchange_rate).
            $table->decimal('exchange_rate', 20, 10)->default(1)->after('original_total_amount');
            $table->date('rate_date')->nullable()->after('exchange_rate');
            $table->string('rate_source')->nullable()->after('rate_date');
        });

        // Backfill existing rows: they are already stored in the user's currency,
        // so original == stored and the rate is 1. Correlated subquery works on
        // both MySQL and SQLite.
        DB::statement(<<<'SQL'
            UPDATE transactions
            SET currency = (
                SELECT s.currency FROM app_user_settings s
                WHERE s.app_user_id = transactions.app_user_id
            )
            WHERE currency IS NULL
        SQL);

        DB::statement(<<<'SQL'
            UPDATE transactions
            SET original_currency = currency,
                original_total_amount = total_amount
            WHERE original_currency IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn([
                'currency',
                'original_currency',
                'original_total_amount',
                'exchange_rate',
                'rate_date',
                'rate_source',
            ]);
        });
    }
};
