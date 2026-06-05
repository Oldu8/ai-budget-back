<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Opaque refresh tokens. Only the hash is stored; tokens rotate on use.
     */
    public function up(): void
    {
        Schema::create('app_user_refresh_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_user_id')->constrained('app_users')->cascadeOnDelete();
            $table->string('token_hash', 64)->unique(); // sha256 hex
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index('app_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_user_refresh_tokens');
    }
};
