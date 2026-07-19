<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class SitemapControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_sitemap_includes_published_posts(): void
    {
        Post::create([
            'locale' => 'en', 'translation_group_id' => (string) Str::uuid(), 'slug' => 'included-post',
            'title' => 'Included', 'status' => Post::STATUS_PUBLISHED, 'published_at' => now()->subDay(),
        ]);

        $response = $this->get('/sitemap.xml');

        $response->assertOk();
        $this->assertStringStartsWith('application/xml', $response->headers->get('Content-Type'));
        $response->assertSee('included-post', false);
    }

    public function test_sitemap_excludes_drafts(): void
    {
        Post::create([
            'locale' => 'en', 'translation_group_id' => (string) Str::uuid(), 'slug' => 'draft-post',
            'title' => 'Draft', 'status' => Post::STATUS_DRAFT, 'published_at' => null,
        ]);

        $response = $this->get('/sitemap.xml');

        $response->assertDontSee('draft-post', false);
    }

    public function test_robots_txt_points_to_the_sitemap_and_disallows_admin(): void
    {
        $response = $this->get('/robots.txt');

        $response->assertOk();
        $this->assertStringStartsWith('text/plain', $response->headers->get('Content-Type'));
        $response->assertSee('Disallow: /admin', false);
        $response->assertSee('Sitemap: '.url('/sitemap.xml'), false);
    }
}
