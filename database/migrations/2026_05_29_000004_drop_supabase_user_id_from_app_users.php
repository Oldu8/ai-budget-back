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
