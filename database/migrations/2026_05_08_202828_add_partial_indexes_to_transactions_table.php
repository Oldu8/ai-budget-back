<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Replaces two full indexes with two partial indexes that pre-filter
 * confirmed_at IS NOT NULL at the storage level.
 *
 * Every read query on transactions already includes this condition, so the
 * partial indexes are strictly smaller, faster to scan, and cheaper to maintain
 * on INSERT/UPDATE than the originals they replace.
 *
 * idx 1 (app_user_id, date)       → ManualTransactionController (type is optional)
 * idx 2 (app_user_id, date, type) → AnalyticsDashboardService, TransactionSummaryController,
 *                                    ExpenseExportController (always filter type = expense)
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP INDEX IF EXISTS transactions_app_user_id_date_index');
        DB::statement('DROP INDEX IF EXISTS transactions_app_user_id_date_type_index');

        // Used by queries that filter on date but not always on type
        DB::statement('
            CREATE INDEX transactions_confirmed_user_date_idx
                ON transactions (app_user_id, date)
                WHERE confirmed_at IS NOT NULL
        ');

        // Used by queries that always filter type = expense
        DB::statement('
            CREATE INDEX transactions_confirmed_user_date_type_idx
                ON transactions (app_user_id, date, type)
                WHERE confirmed_at IS NOT NULL
        ');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS transactions_confirmed_user_date_idx');
        DB::statement('DROP INDEX IF EXISTS transactions_confirmed_user_date_type_idx');

        DB::statement('CREATE INDEX transactions_app_user_id_date_index ON transactions (app_user_id, date)');
        DB::statement('CREATE INDEX transactions_app_user_id_date_type_index ON transactions (app_user_id, date, type)');
    }
};
