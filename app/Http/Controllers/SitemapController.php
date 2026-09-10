<?php

namespace App\Http\Controllers;

use App\Models\Post;
use Illuminate\Http\Response;

class SitemapController extends Controller
{
    public function index(): Response
    {
        $posts = Post::query()
            ->where('locale', 'en')
            ->published()
            ->orderByDesc('published_at')
            ->get();

        return response()
            ->view('public.sitemap', ['posts' => $posts])
            ->header('Content-Type', 'application/xml');
    }

    public function robots(): Response
    {
        // AI assistant / answer-engine crawlers, explicitly allowed for GEO (citability in AI
        // search) — listed by name since many boilerplates block them by default; here there's
        // nothing to override, but this documents the decision instead of relying on the
        // wildcard block below being silently permissive.
        $aiCrawlers = ['GPTBot', 'OAI-SearchBot', 'ChatGPT-User', 'ClaudeBot', 'Claude-Web', 'PerplexityBot', 'Google-Extended'];

        $lines = [
            'User-agent: *',
            'Allow: /',
            'Disallow: /admin',
            '',
        ];

        foreach ($aiCrawlers as $bot) {
            $lines[] = "User-agent: {$bot}";
            $lines[] = 'Allow: /';
            $lines[] = '';
        }

        $lines[] = 'Sitemap: '.url('/sitemap.xml');

        return response(implode("\n", $lines), 200)
            ->header('Content-Type', 'text/plain');
    }
}
