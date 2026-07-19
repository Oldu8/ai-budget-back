<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SeedCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_statuses_seed_populates_the_statuses_table(): void
    {
        $this->artisan('statuses:seed')->assertSuccessful();

        $this->assertDatabaseHas('statuses', ['name' => 'active']);
        $this->assertDatabaseHas('statuses', ['name' => 'past_due']);
    }

    public function test_statuses_seed_is_idempotent(): void
    {
        $this->artisan('statuses:seed')->assertSuccessful();
        $this->artisan('statuses:seed')->assertSuccessful();

        $this->assertDatabaseCount('statuses', 5);
    }

    public function test_plans_seed_populates_the_plans_table(): void
    {
        $this->artisan('plans:seed')->assertSuccessful();

        $this->assertDatabaseHas('plans', ['type' => 'free', 'check_limit' => 20]);
        $this->assertDatabaseHas('plans', ['type' => 'premium']);
    }

    public function test_visibility_questions_seed_populates_the_table(): void
    {
        $this->artisan('visibility-questions:seed')->assertSuccessful();

        $this->assertDatabaseCount('visibility_questions', 10);
        $this->assertDatabaseHas('visibility_questions', ['question_key' => 'has_children']);
    }
}
