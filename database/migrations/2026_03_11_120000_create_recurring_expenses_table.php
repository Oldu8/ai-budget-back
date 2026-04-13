<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurring_expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_user_id')
                ->constrained('app_users')
                ->cascadeOnDelete();
            $table->foreignId('category_id')
                ->constrained('categories')
                ->restrictOnDelete();
            $table->integer('amount');
            $table->string('label')->nullable();
            $table->dateTime('starts_at');
            $table->string('frequency'); // weekly | monthly | yearly
            $table->integer('interval')->default(1);
            $table->dateTime('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->dateTime('last_run_at')->nullable();
            $table->dateTime('next_run_at');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['app_user_id', 'is_active', 'next_run_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recurring_expenses');
    }
};
