<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_user_category_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_user_id')
                ->constrained('app_users')
                ->cascadeOnDelete();
            $table->foreignId('category_id')
                ->constrained('categories')
                ->cascadeOnDelete();
            $table->string('visibility_tag')->nullable();
            $table->unique(['app_user_id', 'category_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_user_category_settings');
    }
};
