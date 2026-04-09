<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stripe_checkout_logs', function (Blueprint $table) {
            $table->id();
            $table->string('stripe_checkout_session_id')->unique();
            $table->string('stripe_customer_id')->nullable();
            $table->foreignId('app_user_id')->nullable()->constrained('app_users')->nullOnDelete();
            $table->string('plan_type')->nullable();
            $table->string('currency', 8)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_checkout_logs');
    }
};
