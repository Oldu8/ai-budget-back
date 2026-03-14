<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Visibility questions for onboarding funnel. Seeded by VisibilityQuestionSeeder.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visibility_questions', function (Blueprint $table) {
            $table->id();
            $table->string('visibility_tag');
            $table->string('question_key')->unique();
            $table->string('type')->default('boolean');
            $table->string('translation_key');
            $table->unsignedSmallInteger('order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visibility_questions');
    }
};
