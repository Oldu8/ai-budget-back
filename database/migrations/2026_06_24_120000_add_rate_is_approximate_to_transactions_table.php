<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // True when the conversion used a current (not historical) rate because
            // the original currency has no free historical source — the fallback
            // path for unsupported currencies. Drives a "rate is approximate" hint.
            $table->boolean('rate_is_approximate')->default(false)->after('rate_source');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('rate_is_approximate');
        });
    }
};
