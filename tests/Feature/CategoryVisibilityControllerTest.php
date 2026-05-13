<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AppUserCategorySetting;
use App\Models\Category;
use App\Models\VisibilityQuestion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CategoryVisibilityControllerTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // funnelQuestions
    // -------------------------------------------------------------------------

    public function test_funnel_questions_returns_active_questions(): void
    {
        VisibilityQuestion::create(['visibility_tag' => 'food', 'question_key' => 'q1', 'translation_key' => 'k1', 'order' => 1, 'is_active' => true]);
        VisibilityQuestion::create(['visibility_tag' => 'housing', 'question_key' => 'q2', 'translation_key' => 'k2', 'order' => 2, 'is_active' => false]);

        $user = $this->makeAppUser();
        $response = $this->apiAs($user)->getJson('/api/funnel/visibility-questions');

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame('food', $data[0]['visibility_tag']);
    }

    public function test_funnel_questions_includes_required_fields(): void
    {
        VisibilityQuestion::create(['visibility_tag' => 'food', 'question_key' => 'q1', 'translation_key' => 'k1', 'order' => 1, 'is_active' => true]);

        $user = $this->makeAppUser();
        $response = $this->apiAs($user)->getJson('/api/funnel/visibility-questions');

        $item = $response->json('data.0');
        $this->assertArrayHasKey('visibility_tag', $item);
        $this->assertArrayHasKey('question_key', $item);
        $this->assertArrayHasKey('type', $item);
        $this->assertArrayHasKey('order', $item);
        $this->assertArrayHasKey('translation_key', $item);
    }

    // -------------------------------------------------------------------------
    // visibilityTags
    // -------------------------------------------------------------------------

    public function test_visibility_tags_returns_all_tags_with_enabled_status(): void
    {
        VisibilityQuestion::create(['visibility_tag' => 'food', 'question_key' => 'q1', 'translation_key' => 'k1', 'order' => 1, 'is_active' => true]);
        VisibilityQuestion::create(['visibility_tag' => 'housing', 'question_key' => 'q2', 'translation_key' => 'k2', 'order' => 2, 'is_active' => true]);

        $cat = Category::create(['name' => 'Groceries', 'label' => 'Groceries', 'visibility_tag' => 'food']);

        $user = $this->makeAppUser();
        AppUserCategorySetting::create(['app_user_id' => $user->id, 'category_id' => $cat->id, 'visibility_tag' => 'food']);

        $response = $this->apiAs($user)->getJson('/api/visibility-tags');

        $response->assertOk();
        $byTag = collect($response->json('data'))->keyBy('visibility_tag');
        $this->assertFalse($byTag['food']['enabled']);
        $this->assertTrue($byTag['housing']['enabled']);
    }

    // -------------------------------------------------------------------------
    // update — enable
    // -------------------------------------------------------------------------

    public function test_update_enable_deletes_user_settings_for_tag(): void
    {
        $vq = VisibilityQuestion::create(['visibility_tag' => 'food', 'question_key' => 'q1', 'translation_key' => 'k1', 'order' => 1, 'is_active' => true]);
        $cat = Category::create(['name' => 'Groceries', 'label' => 'Groceries', 'visibility_tag' => 'food']);

        $user = $this->makeAppUser();
        AppUserCategorySetting::create(['app_user_id' => $user->id, 'category_id' => $cat->id, 'visibility_tag' => 'food']);

        $this->apiAs($user)->patchJson('/api/me/category-visibility', [
            'visibility_tag' => 'food',
            'enabled' => true,
        ])->assertOk();

        $this->assertDatabaseMissing('app_user_category_settings', [
            'app_user_id' => $user->id,
            'visibility_tag' => 'food',
        ]);
    }

    // -------------------------------------------------------------------------
    // update — disable
    // -------------------------------------------------------------------------

    public function test_update_disable_creates_settings_for_all_matching_categories(): void
    {
        VisibilityQuestion::create(['visibility_tag' => 'food', 'question_key' => 'q1', 'translation_key' => 'k1', 'order' => 1, 'is_active' => true]);
        $cat1 = Category::create(['name' => 'Groceries', 'label' => 'Groceries', 'visibility_tag' => 'food']);
        $cat2 = Category::create(['name' => 'Restaurants', 'label' => 'Restaurants', 'visibility_tag' => 'food']);

        $user = $this->makeAppUser();

        $this->apiAs($user)->patchJson('/api/me/category-visibility', [
            'visibility_tag' => 'food',
            'enabled' => false,
        ])->assertOk();

        $this->assertDatabaseHas('app_user_category_settings', ['app_user_id' => $user->id, 'category_id' => $cat1->id]);
        $this->assertDatabaseHas('app_user_category_settings', ['app_user_id' => $user->id, 'category_id' => $cat2->id]);
    }

    public function test_update_disable_is_idempotent(): void
    {
        VisibilityQuestion::create(['visibility_tag' => 'food', 'question_key' => 'q1', 'translation_key' => 'k1', 'order' => 1, 'is_active' => true]);
        $cat = Category::create(['name' => 'Groceries', 'label' => 'Groceries', 'visibility_tag' => 'food']);

        $user = $this->makeAppUser();

        $this->apiAs($user)->patchJson('/api/me/category-visibility', ['visibility_tag' => 'food', 'enabled' => false]);
        $this->apiAs($user)->patchJson('/api/me/category-visibility', ['visibility_tag' => 'food', 'enabled' => false]);

        $count = AppUserCategorySetting::where('app_user_id', $user->id)->where('category_id', $cat->id)->count();
        $this->assertSame(1, $count);
    }

    public function test_update_disable_returns_422_when_no_categories_found_for_tag(): void
    {
        VisibilityQuestion::create(['visibility_tag' => 'food', 'question_key' => 'q1', 'translation_key' => 'k1', 'order' => 1, 'is_active' => true]);

        $user = $this->makeAppUser();

        $this->apiAs($user)->patchJson('/api/me/category-visibility', [
            'visibility_tag' => 'food',
            'enabled' => false,
        ])->assertUnprocessable();
    }
}
