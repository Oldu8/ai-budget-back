<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_user_id')
                ->constrained('app_users')
                ->cascadeOnDelete();
            $table->foreignId('category_id')
                ->constrained('categories')
                ->restrictOnDelete();
            $table->integer('amount');
            $table->date('date');
            $table->string('type'); // income | expense
            $table->string('created_by'); // user | ai
            $table->json('raw_ai_data')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->string('img_path')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['app_user_id', 'date']);
            $table->index(['app_user_id', 'category_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
