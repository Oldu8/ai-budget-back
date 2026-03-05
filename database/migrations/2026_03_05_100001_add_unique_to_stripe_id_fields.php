<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_subscriptions', function (Blueprint $table) {
            $table->unique('payment_subscription_id');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->unique('payment_invoice_id');
        });
    }

    public function down(): void
    {
        Schema::table('user_subscriptions', function (Blueprint $table) {
            $table->dropUnique(['payment_subscription_id']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique(['payment_invoice_id']);
        });
    }
};
