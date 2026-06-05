<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Links an app_user to an external identity provider (google | apple).
     * One provider account maps to exactly one app_user.
     */
    public function up(): void
    {
        Schema::create('app_user_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_user_id')->constrained('app_users')->cascadeOnDelete();
            $table->string('provider');           // google | apple
            $table->string('provider_user_id');   // provider's stable subject (sub)
            $table->timestamps();

            $table->unique(['provider', 'provider_user_id']);
            $table->index('app_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_user_identities');
    }
};
