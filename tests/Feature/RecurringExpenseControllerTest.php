<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PlanType;
use App\Models\AppUserCategorySetting;
use App\Models\RecurringExpense;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class RecurringExpenseControllerTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // index
    // -------------------------------------------------------------------------

    public function test_index_returns_only_users_own_expenses(): void
    {
        $user = $this->makeAppUser();
        $other = $this->makeAppUser();
        $this->subscribe($user, $this->makePlan(PlanType::Plus));
        $leaf = $this->makeCategory();

        RecurringExpense::create($this->basePayload($user->id, $leaf->id, next_run_at: '2026-06-01'));
        RecurringExpense::create($this->basePayload($other->id, $leaf->id, next_run_at: '2026-06-01'));

        $response = $this->apiAs($user)->getJson('/api/recurring-expenses');

        $response->assertOk();
        $this->assertCount(1, $response->json());
    }

    public function test_index_returns_expenses_ordered_by_next_run_at(): void
    {
        $user = $this->makeAppUser();
        $this->subscribe($user, $this->makePlan(PlanType::Plus));
        $leaf = $this->makeCategory();

        RecurringExpense::create($this->basePayload($user->id, $leaf->id, next_run_at: '2026-08-01'));
        RecurringExpense::create($this->basePayload($user->id, $leaf->id, next_run_at: '2026-06-01'));
        RecurringExpense::create($this->basePayload($user->id, $leaf->id, next_run_at: '2026-07-01'));

        $response = $this->apiAs($user)->getJson('/api/recurring-expenses');

        $dates = array_column($response->json(), 'next_run_at');
        $sorted = $dates;
        sort($sorted);
        $this->assertSame($sorted, $dates);
    }

    public function test_index_requires_plus_or_premium_plan(): void
    {
        $user = $this->makeAppUser();
        $this->subscribe($user, $this->makePlan(PlanType::Free));

        $this->apiAs($user)->getJson('/api/recurring-expenses')->assertForbidden();
    }

    // -------------------------------------------------------------------------
    // store
    // -------------------------------------------------------------------------

    public function test_store_creates_recurring_expense_and_returns_201(): void
    {
        $user = $this->makeAppUser();
        $this->makeSettings($user, 'EUR', 'en', 'Europe/Berlin');
        $this->subscribe($user, $this->makePlan(PlanType::Plus));
        $leaf = $this->makeCategory();

        $response = $this->apiAs($user)->postJson('/api/recurring-expenses', [
            'category_id' => $leaf->id,
            'amount' => 1500,
            'label' => 'Netflix',
            'starts_at' => '2026-06-01',
            'frequency' => 'monthly',
            'interval' => 1,
        ]);

        $response->assertCreated()
            ->assertJsonPath('category_id', $leaf->id)
            ->assertJsonPath('amount', 1500)
            ->assertJsonPath('label', 'Netflix')
            ->assertJsonPath('frequency', 'monthly')
            ->assertJsonPath('interval', 1)
            ->assertJsonPath('is_active', true);

        $this->assertNotNull($response->json('next_run_at'));
        $this->assertNotNull($response->json('category'));
    }

    public function test_store_interval_defaults_to_1_when_omitted(): void
    {
        $user = $this->makeAppUser();
        $this->subscribe($user, $this->makePlan(PlanType::Plus));
        $leaf = $this->makeCategory();

        $response = $this->apiAs($user)->postJson('/api/recurring-expenses', [
            'category_id' => $leaf->id,
            'amount' => 1000,
            'starts_at' => '2026-06-01',
            'frequency' => 'weekly',
        ]);

        $response->assertCreated()->assertJsonPath('interval', 1);
    }

    public function test_store_with_ends_at(): void
    {
        $user = $this->makeAppUser();
        $this->subscribe($user, $this->makePlan(PlanType::Plus));
        $leaf = $this->makeCategory();

        $response = $this->apiAs($user)->postJson('/api/recurring-expenses', [
            'category_id' => $leaf->id,
            'amount' => 500,
            'starts_at' => '2026-06-01',
            'frequency' => 'monthly',
            'ends_at' => '2026-12-01',
        ]);

        $response->assertCreated();
        $this->assertNotNull($response->json('ends_at'));
    }

    public function test_store_rejects_top_level_category(): void
    {
        $user = $this->makeAppUser();
        $this->subscribe($user, $this->makePlan(PlanType::Plus));
        $leaf = $this->makeCategory();
        $parent = $leaf->parent;

        $response = $this->apiAs($user)->postJson('/api/recurring-expenses', [
            'category_id' => $parent->id,
            'amount' => 1000,
            'starts_at' => '2026-06-01',
            'frequency' => 'monthly',
        ]);

        $response->assertUnprocessable()
            ->assertJsonPath('errors.category_id.0', 'Category must be a Level 2 category.');
    }

    public function test_store_rejects_disabled_category(): void
    {
        $user = $this->makeAppUser();
        $this->subscribe($user, $this->makePlan(PlanType::Plus));
        $leaf = $this->makeCategory();

        AppUserCategorySetting::create([
            'app_user_id' => $user->id,
            'category_id' => $leaf->id,
        ]);

        $response = $this->apiAs($user)->postJson('/api/recurring-expenses', [
            'category_id' => $leaf->id,
            'amount' => 1000,
            'starts_at' => '2026-06-01',
            'frequency' => 'monthly',
        ]);

        $response->assertUnprocessable()
            ->assertJsonPath('errors.category_id.0', 'Category is disabled for this user.');
    }

    // -------------------------------------------------------------------------
    // show
    // -------------------------------------------------------------------------

    public function test_show_returns_own_expense(): void
    {
        $user = $this->makeAppUser();
        $this->subscribe($user, $this->makePlan(PlanType::Plus));
        $leaf = $this->makeCategory();
        $re = RecurringExpense::create($this->basePayload($user->id, $leaf->id));

        $this->apiAs($user)->getJson("/api/recurring-expenses/{$re->id}")
            ->assertOk()
            ->assertJsonPath('id', $re->id);
    }

    public function test_show_returns_404_for_other_users_expense(): void
    {
        $user = $this->makeAppUser();
        $other = $this->makeAppUser();
        $this->subscribe($user, $this->makePlan(PlanType::Plus));
        $leaf = $this->makeCategory();
        $re = RecurringExpense::create($this->basePayload($other->id, $leaf->id));

        $this->apiAs($user)->getJson("/api/recurring-expenses/{$re->id}")
            ->assertNotFound();
    }

    // -------------------------------------------------------------------------
    // update
    // -------------------------------------------------------------------------

    public function test_update_changes_amount_and_label_without_recalculating_next_run_at(): void
    {
        $user = $this->makeAppUser();
        $this->subscribe($user, $this->makePlan(PlanType::Plus));
        $leaf = $this->makeCategory();
        $re = RecurringExpense::create($this->basePayload($user->id, $leaf->id, next_run_at: '2026-09-01'));

        $originalNextRunAt = $re->next_run_at->toDateString();

        $response = $this->apiAs($user)->putJson("/api/recurring-expenses/{$re->id}", [
            'amount' => 9999,
            'label' => 'Updated label',
        ]);

        $response->assertOk()
            ->assertJsonPath('amount', 9999)
            ->assertJsonPath('label', 'Updated label');

        $this->assertSame($originalNextRunAt, $re->fresh()->next_run_at->toDateString());
    }

    public function test_update_recalculates_next_run_at_when_frequency_changes(): void
    {
        $user = $this->makeAppUser();
        $this->subscribe($user, $this->makePlan(PlanType::Plus));
        $leaf = $this->makeCategory();
        $re = RecurringExpense::create($this->basePayload($user->id, $leaf->id, next_run_at: '2026-09-01'));

        $this->apiAs($user)->putJson("/api/recurring-expenses/{$re->id}", [
            'frequency' => 'yearly',
        ])->assertOk();

        $this->assertNotSame('2026-09-01', $re->fresh()->next_run_at->toDateString());
    }

    public function test_update_clears_ends_at_when_null_sent_explicitly(): void
    {
        $user = $this->makeAppUser();
        $this->subscribe($user, $this->makePlan(PlanType::Plus));
        $leaf = $this->makeCategory();
        $re = RecurringExpense::create($this->basePayload($user->id, $leaf->id, ends_at: '2026-12-31'));

        $this->apiAs($user)->putJson("/api/recurring-expenses/{$re->id}", [
            'ends_at' => null,
        ])->assertOk();

        $this->assertNull($re->fresh()->ends_at);
    }

    public function test_update_preserves_ends_at_when_key_not_sent(): void
    {
        $user = $this->makeAppUser();
        $this->subscribe($user, $this->makePlan(PlanType::Plus));
        $leaf = $this->makeCategory();
        $re = RecurringExpense::create($this->basePayload($user->id, $leaf->id, ends_at: '2026-12-31'));

        $this->apiAs($user)->putJson("/api/recurring-expenses/{$re->id}", [
            'amount' => 100,
        ])->assertOk();

        $this->assertNotNull($re->fresh()->ends_at);
    }

    public function test_update_returns_404_for_other_users_expense(): void
    {
        $user = $this->makeAppUser();
        $other = $this->makeAppUser();
        $this->subscribe($user, $this->makePlan(PlanType::Plus));
        $leaf = $this->makeCategory();
        $re = RecurringExpense::create($this->basePayload($other->id, $leaf->id));

        $this->apiAs($user)->putJson("/api/recurring-expenses/{$re->id}", ['amount' => 100])
            ->assertNotFound();
    }

    // -------------------------------------------------------------------------
    // destroy
    // -------------------------------------------------------------------------

    public function test_destroy_soft_deletes_and_returns_204(): void
    {
        $user = $this->makeAppUser();
        $this->subscribe($user, $this->makePlan(PlanType::Plus));
        $leaf = $this->makeCategory();
        $re = RecurringExpense::create($this->basePayload($user->id, $leaf->id));

        $this->apiAs($user)->deleteJson("/api/recurring-expenses/{$re->id}")
            ->assertNoContent();

        $this->assertSoftDeleted('recurring_expenses', ['id' => $re->id]);
    }

    public function test_destroy_returns_404_for_other_users_expense(): void
    {
        $user = $this->makeAppUser();
        $other = $this->makeAppUser();
        $this->subscribe($user, $this->makePlan(PlanType::Plus));
        $leaf = $this->makeCategory();
        $re = RecurringExpense::create($this->basePayload($other->id, $leaf->id));

        $this->apiAs($user)->deleteJson("/api/recurring-expenses/{$re->id}")
            ->assertNotFound();
    }

    // -------------------------------------------------------------------------
    // toggle
    // -------------------------------------------------------------------------

    public function test_toggle_disable_sets_is_active_false(): void
    {
        $user = $this->makeAppUser();
        $this->subscribe($user, $this->makePlan(PlanType::Plus));
        $leaf = $this->makeCategory();
        $re = RecurringExpense::create($this->basePayload($user->id, $leaf->id, is_active: true));

        $this->apiAs($user)->patchJson("/api/recurring-expenses/{$re->id}/toggle", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('is_active', false);

        $this->assertFalse($re->fresh()->is_active);
    }

    public function test_toggle_enable_sets_is_active_true_and_normalizes_next_run_at(): void
    {
        $user = $this->makeAppUser();
        $this->makeSettings($user, 'EUR', 'en', 'UTC');
        $this->subscribe($user, $this->makePlan(PlanType::Plus));
        $leaf = $this->makeCategory();
        // Stale next_run_at in the past to trigger normalization
        $re = RecurringExpense::create($this->basePayload($user->id, $leaf->id, is_active: false, next_run_at: '2020-01-01'));

        $response = $this->apiAs($user)->patchJson("/api/recurring-expenses/{$re->id}/toggle", ['is_active' => true]);

        $response->assertOk()->assertJsonPath('is_active', true);
        $this->assertTrue($re->fresh()->next_run_at >= now());
    }

    public function test_toggle_returns_404_for_other_users_expense(): void
    {
        $user = $this->makeAppUser();
        $other = $this->makeAppUser();
        $this->subscribe($user, $this->makePlan(PlanType::Plus));
        $leaf = $this->makeCategory();
        $re = RecurringExpense::create($this->basePayload($other->id, $leaf->id));

        $this->apiAs($user)->patchJson("/api/recurring-expenses/{$re->id}/toggle", ['is_active' => false])
            ->assertNotFound();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** @param array<string, mixed> $overrides */
    private function basePayload(
        int $userId,
        int $categoryId,
        string $next_run_at = '2026-06-01',
        ?string $ends_at = null,
        bool $is_active = true,
    ): array {
        return [
            'app_user_id' => $userId,
            'category_id' => $categoryId,
            'amount' => 1000,
            'label' => 'Test',
            'starts_at' => '2026-06-01',
            'frequency' => 'monthly',
            'interval' => 1,
            'ends_at' => $ends_at,
            'is_active' => $is_active,
            'next_run_at' => $next_run_at,
        ];
    }
}
