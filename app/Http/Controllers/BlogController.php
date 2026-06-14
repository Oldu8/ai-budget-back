<?php

namespace App\Http\Controllers;

use App\Models\Post;
use Illuminate\View\View;

class BlogController extends Controller
{
    /**
     * Language the public site currently serves. Single-language for now;
     * articles tagged with any other locale stay hidden from the public blog.
     */
    private const SITE_LOCALE = 'en';

    public function index(): View
    {
        app()->setLocale(self::SITE_LOCALE);

        $posts = Post::query()
            ->where('locale', self::SITE_LOCALE)
            ->published()
            ->orderByDesc('published_at')
            ->paginate(9);

        return view('public.blog.index', [
            'locale' => self::SITE_LOCALE,
            'posts' => $posts,
            'title' => __('blog.title'),
            'metaDescription' => __('blog.subtitle'),
            'canonical' => route('blog.index'),
        ]);
    }

    public function show(string $slug): View
    {
        app()->setLocale(self::SITE_LOCALE);

        $post = Post::query()
            ->where('locale', self::SITE_LOCALE)
            ->where('slug', $slug)
            ->published()
            ->firstOrFail();

        $related = Post::query()
            ->where('locale', self::SITE_LOCALE)
            ->published()
            ->whereKeyNot($post->id)
            ->orderByDesc('published_at')
            ->limit(3)
            ->get();

        return view('public.blog.show', [
            'locale' => self::SITE_LOCALE,
            'post' => $post,
            'related' => $related,
            'title' => $post->metaTitleValue(),
            'metaDescription' => $post->metaDescriptionValue(),
            'canonical' => route('blog.show', $post->slug),
            'ogType' => 'article',
            'ogImage' => $post->coverUrl(),
        ]);
    }
}
