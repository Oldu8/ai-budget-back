<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Local auth moves off Supabase:
     * - supabase_user_id becomes nullable (legacy; no longer used for auth).
     * - password is added (nullable: null for social-only users).
     */
    public function up(): void
    {
        Schema::table('app_users', function (Blueprint $table) {
            $table->uuid('supabase_user_id')->nullable()->change();
            $table->string('password')->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('app_users', function (Blueprint $table) {
            $table->dropColumn('password');
            $table->uuid('supabase_user_id')->nullable(false)->change();
        });
    }
};
