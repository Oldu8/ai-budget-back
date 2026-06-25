<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            // ISO 4217 codes. Stored as a direct cross pair so conversion is a
            // single lookup: 1 unit of `from_currency` = `rate` units of `to_currency`.
            $table->string('from_currency', 3);
            $table->string('to_currency', 3);
            $table->date('date');
            // Units of `to_currency` per 1 unit of `from_currency` on `date`.
            $table->decimal('rate', 24, 12);
            // Origin of the quote: 'ecb' (via Frankfurter), 'nbu', or 'ecb+nbu'.
            $table->string('source');
            $table->timestamps();

            // Also serves the "(from, to) on/just-before date" fallback lookup.
            $table->unique(['from_currency', 'to_currency', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
    }
};
