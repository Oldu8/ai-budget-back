<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CategoriesResetCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_force_truncates_categories_without_prompting(): void
    {
        Category::create(['name' => 'housing', 'label' => 'Housing']);

        $this->artisan('categories:reset', ['--force' => true])->assertSuccessful();

        $this->assertDatabaseCount('categories', 0);
    }

    public function test_resets_the_id_sequence_to_start_from_one(): void
    {
        $first = Category::create(['name' => 'housing', 'label' => 'Housing']);
        $this->assertGreaterThanOrEqual(1, $first->id);

        $this->artisan('categories:reset', ['--force' => true])->assertSuccessful();

        $new = Category::create(['name' => 'transport', 'label' => 'Transport']);
        $this->assertSame(1, $new->id);
    }

    public function test_without_force_aborts_when_not_confirmed(): void
    {
        Category::create(['name' => 'housing', 'label' => 'Housing']);

        $this->artisan('categories:reset')
            ->expectsConfirmation(
                'This will permanently delete all categories and related data (transaction_items, app_user_category_settings, recurring_expenses). Continue?',
                'no',
            )
            ->assertFailed();

        $this->assertDatabaseCount('categories', 1);
    }

    public function test_without_force_proceeds_when_confirmed(): void
    {
        Category::create(['name' => 'housing', 'label' => 'Housing']);

        $this->artisan('categories:reset')
            ->expectsConfirmation(
                'This will permanently delete all categories and related data (transaction_items, app_user_category_settings, recurring_expenses). Continue?',
                'yes',
            )
            ->assertSuccessful();

        $this->assertDatabaseCount('categories', 0);
    }
}
