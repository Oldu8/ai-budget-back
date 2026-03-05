<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_user_settings', function (Blueprint $table) {
            $table->unique('app_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('app_user_settings', function (Blueprint $table) {
            $table->dropUnique(['app_user_id']);
        });
    }
};
