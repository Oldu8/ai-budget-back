<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SeedInitialCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_statuses_categories_plans_visibility_questions_and_admin_user(): void
    {
        // .env.testing already sets ADMIN_PASSWORD, so AdminUserSeeder creates the admin row too.
        $this->artisan('app:seed-initial')->assertSuccessful();

        $this->assertDatabaseHas('statuses', ['name' => 'active']);
        $this->assertDatabaseHas('plans', ['type' => 'free']);
        $this->assertDatabaseCount('visibility_questions', 10);
        $this->assertGreaterThan(0, Category::count());
        $this->assertDatabaseHas('users', ['email' => 'oldu2002@gmail.com']);
    }
}
