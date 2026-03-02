<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('type')->unique(); // e.g. free, pro, premium
            $table->string('payment_price_id')->nullable();
            $table->integer('price');
            $table->string('label');
            $table->text('description')->nullable();
            $table->integer('check_limit');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
