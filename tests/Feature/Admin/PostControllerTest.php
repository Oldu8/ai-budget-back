<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PostControllerTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create(['name' => 'Admin', 'email' => 'admin@test.com', 'password' => 'password123']);
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'locale' => 'en',
            'title' => 'Hello World',
            'excerpt' => 'A short excerpt',
            'body' => 'Full body text',
            'meta_title' => null,
            'meta_description' => null,
            'status' => Post::STATUS_DRAFT,
        ], $overrides);
    }

    public function test_index_lists_posts(): void
    {
        Post::create($this->validPayload(['slug' => 'first-post', 'translation_group_id' => Str::uuid()]));

        $response = $this->actingAs($this->admin())->get('/admin/posts');

        $response->assertOk()->assertViewIs('admin.posts.index');
        $this->assertCount(1, $response->viewData('posts'));
    }

    public function test_create_renders_a_draft_post_by_default(): void
    {
        $response = $this->actingAs($this->admin())->get('/admin/posts/create');

        $response->assertOk()->assertViewIs('admin.posts.create');
        $this->assertSame(Post::STATUS_DRAFT, $response->viewData('post')->status);
    }

    public function test_store_creates_post_with_generated_slug_and_translation_group(): void
    {
        $response = $this->actingAs($this->admin())->post('/admin/posts', $this->validPayload(['title' => 'My Great Post']));

        $post = Post::firstOrFail();
        $response->assertRedirect(route('admin.posts.edit', $post));
        $this->assertSame('my-great-post', $post->slug);
        $this->assertNotNull($post->translation_group_id);
        $this->assertNull($post->published_at);
    }

    public function test_store_respects_an_explicit_slug(): void
    {
        $this->actingAs($this->admin())->post('/admin/posts', $this->validPayload(['slug' => 'custom-slug']));

        $this->assertDatabaseHas('posts', ['slug' => 'custom-slug']);
    }

    public function test_store_sets_published_at_when_status_is_published(): void
    {
        $this->actingAs($this->admin())->post('/admin/posts', $this->validPayload(['status' => Post::STATUS_PUBLISHED]));

        $post = Post::firstOrFail();
        $this->assertNotNull($post->published_at);
    }

    public function test_store_reuses_a_provided_translation_group_id(): void
    {
        $groupId = (string) Str::uuid();

        $this->actingAs($this->admin())->post('/admin/posts', $this->validPayload(['translation_group_id' => $groupId]));

        $this->assertDatabaseHas('posts', ['translation_group_id' => $groupId]);
    }

    public function test_store_validates_required_fields(): void
    {
        $this->actingAs($this->admin())->post('/admin/posts', ['locale' => 'en'])
            ->assertSessionHasErrors(['title', 'status']);
    }

    public function test_edit_renders_the_post(): void
    {
        $post = Post::create($this->validPayload(['slug' => 'edit-me', 'translation_group_id' => Str::uuid()]));

        $response = $this->actingAs($this->admin())->get("/admin/posts/{$post->id}/edit");

        $response->assertOk()->assertViewIs('admin.posts.edit');
        $this->assertSame($post->id, $response->viewData('post')->id);
    }

    public function test_update_modifies_the_post(): void
    {
        $post = Post::create($this->validPayload(['slug' => 'old-slug', 'translation_group_id' => Str::uuid()]));

        $this->actingAs($this->admin())->put("/admin/posts/{$post->id}", $this->validPayload(['title' => 'Updated title']));

        $this->assertSame('Updated title', $post->fresh()->title);
    }

    public function test_update_sets_translation_group_id_when_provided(): void
    {
        $post = Post::create($this->validPayload(['slug' => 'set-group', 'translation_group_id' => Str::uuid()]));
        $newGroupId = (string) Str::uuid();

        $this->actingAs($this->admin())->put("/admin/posts/{$post->id}", [
            ...$this->validPayload(),
            'translation_group_id' => $newGroupId,
        ]);

        $this->assertSame($newGroupId, $post->fresh()->translation_group_id);
    }

    public function test_update_keeps_translation_group_id_when_not_provided(): void
    {
        $groupId = (string) Str::uuid();
        $post = Post::create($this->validPayload(['slug' => 'keep-group', 'translation_group_id' => $groupId]));

        $payload = $this->validPayload(['title' => 'Still same group']);
        unset($payload['translation_group_id']);
        $this->actingAs($this->admin())->put("/admin/posts/{$post->id}", $payload);

        $this->assertSame($groupId, $post->fresh()->translation_group_id);
    }

    public function test_update_uploads_a_cover_image(): void
    {
        Storage::fake('public');
        $post = Post::create($this->validPayload(['slug' => 'with-cover', 'translation_group_id' => Str::uuid()]));

        $this->actingAs($this->admin())->put("/admin/posts/{$post->id}", [
            ...$this->validPayload(),
            'cover' => UploadedFile::fake()->create('cover.jpg', 100, 'image/jpeg'),
        ]);

        $post->refresh();
        $this->assertNotNull($post->cover_image);
        Storage::disk('public')->assertExists($post->cover_image);
    }

    public function test_destroy_deletes_the_post(): void
    {
        $post = Post::create($this->validPayload(['slug' => 'to-delete', 'translation_group_id' => Str::uuid()]));

        $response = $this->actingAs($this->admin())->delete("/admin/posts/{$post->id}");

        $response->assertRedirect(route('admin.posts.index'));
        $this->assertDatabaseMissing('posts', ['id' => $post->id]);
    }

    public function test_requires_authentication(): void
    {
        $this->get('/admin/posts')->assertRedirect(route('login'));
    }
}
