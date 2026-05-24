<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AppUserCategorySetting;
use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CategoryControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_category_tree_with_correct_structure(): void
    {
        $leaf = $this->makeCategory();
        $user = $this->makeAppUser();

        $response = $this->apiAs($user)->getJson('/api/categories');

        $response->assertOk()->assertJsonStructure([
            'data' => [['id', 'name', 'label', 'category_id', 'visibility_tag', 'children']],
        ]);
        $child = $response->json('data.0.children.0');
        $this->assertArrayHasKey('id', $child);
        $this->assertArrayHasKey('name', $child);
        $this->assertArrayHasKey('label', $child);
        $this->assertArrayHasKey('category_id', $child);
        $this->assertSame($leaf->id, $child['id']);
    }

    public function test_excludes_disabled_leaf_categories(): void
    {
        $leaf = $this->makeCategory();
        $user = $this->makeAppUser();

        AppUserCategorySetting::create([
            'app_user_id' => $user->id,
            'category_id' => $leaf->id,
        ]);

        $response = $this->apiAs($user)->getJson('/api/categories');

        $response->assertOk();
        $children = $response->json('data.0.children');
        $childIds = array_column($children, 'id');
        $this->assertNotContains($leaf->id, $childIds);
    }

    public function test_excludes_parent_category_when_itself_is_disabled(): void
    {
        $leaf = $this->makeCategory();
        $parent = $leaf->parent;
        $user = $this->makeAppUser();

        AppUserCategorySetting::create([
            'app_user_id' => $user->id,
            'category_id' => $parent->id,
        ]);

        $response = $this->apiAs($user)->getJson('/api/categories');

        $data = $response->json('data');
        $parentIds = array_column($data, 'id');
        $this->assertNotContains($parent->id, $parentIds);
    }

    public function test_does_not_exclude_other_users_disabled_categories(): void
    {
        $leaf = $this->makeCategory();
        $user = $this->makeAppUser();
        $other = $this->makeAppUser();

        AppUserCategorySetting::create([
            'app_user_id' => $other->id,
            'category_id' => $leaf->id,
        ]);

        $response = $this->apiAs($user)->getJson('/api/categories');

        $children = $response->json('data.0.children');
        $childIds = array_column($children, 'id');
        $this->assertContains($leaf->id, $childIds);
    }

    public function test_visibility_tag_mode_returns_matching_categories_without_user_filter(): void
    {
        $parent = Category::create(['name' => 'Housing', 'label' => 'Housing', 'visibility_tag' => 'housing']);
        Category::create(['name' => 'Rent', 'label' => 'Rent', 'category_id' => $parent->id, 'visibility_tag' => 'housing']);
        Category::create(['name' => 'Utilities', 'label' => 'Utilities', 'category_id' => $parent->id]);

        $user = $this->makeAppUser();
        AppUserCategorySetting::create(['app_user_id' => $user->id, 'category_id' => $parent->id]);

        $response = $this->apiAs($user)->getJson('/api/categories?visibility_tag=housing');

        $response->assertOk();
        $data = $response->json('data');
        $this->assertNotEmpty($data);
        // Parent with the tag appears even though user has it disabled
        $parentIds = array_column($data, 'id');
        $this->assertContains($parent->id, $parentIds);
        // Only children with the tag are included
        $children = $data[0]['children'];
        foreach ($children as $child) {
            $cat = Category::find($child['id']);
            $this->assertSame('housing', $cat->visibility_tag);
        }
    }
}
