<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class BlogControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makePost(array $overrides = []): Post
    {
        return Post::create(array_merge([
            'locale' => 'en',
            'translation_group_id' => (string) Str::uuid(),
            'slug' => 'post-'.Str::random(8),
            'title' => 'A Post',
            'excerpt' => 'Excerpt',
            'body' => 'Body',
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now()->subDay(),
        ], $overrides));
    }

    public function test_index_lists_published_english_posts(): void
    {
        $this->makePost(['title' => 'Published One']);

        $response = $this->get('/blog');

        $response->assertOk()->assertViewIs('public.blog.index');
        $this->assertCount(1, $response->viewData('posts'));
    }

    public function test_index_excludes_drafts(): void
    {
        $this->makePost(['status' => Post::STATUS_DRAFT, 'published_at' => null]);

        $response = $this->get('/blog');

        $this->assertCount(0, $response->viewData('posts'));
    }

    public function test_index_excludes_posts_scheduled_in_the_future(): void
    {
        $this->makePost(['published_at' => now()->addDay()]);

        $response = $this->get('/blog');

        $this->assertCount(0, $response->viewData('posts'));
    }

    public function test_index_excludes_other_locales(): void
    {
        $this->makePost(['locale' => 'uk']);

        $response = $this->get('/blog');

        $this->assertCount(0, $response->viewData('posts'));
    }

    public function test_show_displays_a_published_post(): void
    {
        $post = $this->makePost(['slug' => 'my-article', 'title' => 'My Article']);

        $response = $this->get('/blog/my-article');

        $response->assertOk()->assertViewIs('public.blog.show');
        $this->assertSame($post->id, $response->viewData('post')->id);
        $this->assertSame('My Article', $response->viewData('title'));
    }

    public function test_show_returns_404_for_a_draft_post(): void
    {
        $this->makePost(['slug' => 'draft-article', 'status' => Post::STATUS_DRAFT, 'published_at' => null]);

        $this->get('/blog/draft-article')->assertNotFound();
    }

    public function test_show_returns_404_for_unknown_slug(): void
    {
        $this->get('/blog/does-not-exist')->assertNotFound();
    }

    public function test_show_includes_up_to_three_related_posts_excluding_itself(): void
    {
        $post = $this->makePost(['slug' => 'main-article']);
        $this->makePost(['slug' => 'related-1']);
        $this->makePost(['slug' => 'related-2']);
        $this->makePost(['slug' => 'related-3']);
        $this->makePost(['slug' => 'related-4']);

        $response = $this->get('/blog/main-article');

        $related = $response->viewData('related');
        $this->assertCount(3, $related);
        $this->assertNotContains($post->id, $related->pluck('id'));
    }
}
