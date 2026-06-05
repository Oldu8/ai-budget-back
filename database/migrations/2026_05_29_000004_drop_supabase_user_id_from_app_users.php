<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Auth no longer uses Supabase. The legacy bridge column is removed entirely
     * (no real users to preserve). Dropping the column also drops its unique index.
     */
    public function up(): void
    {
        // Drop the unique index first: SQLite can't drop a column that still has a
        // dependent index (it leaves a dangling reference during the table rebuild).
        Schema::table('app_users', function (Blueprint $table) {
            $table->dropUnique('app_users_supabase_user_id_unique');
        });

        Schema::table('app_users', function (Blueprint $table) {
            $table->dropColumn('supabase_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('app_users', function (Blueprint $table) {
            $table->uuid('supabase_user_id')->nullable()->unique();
        });
    }
};
