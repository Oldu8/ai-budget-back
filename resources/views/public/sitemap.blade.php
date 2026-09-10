{!! '<'.'?xml version="1.0" encoding="UTF-8"?>'."\n" !!}
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
    <url>
        <loc>{{ route('home') }}</loc>
        <changefreq>weekly</changefreq>
    </url>
    <url>
        <loc>{{ route('blog.index') }}</loc>
        <changefreq>daily</changefreq>
    </url>
    <url>
        <loc>{{ route('privacy') }}</loc>
        <changefreq>yearly</changefreq>
    </url>
    <url>
        <loc>{{ route('terms') }}</loc>
        <changefreq>yearly</changefreq>
    </url>
    <url>
        <loc>{{ route('what-is-vtrata') }}</loc>
        <changefreq>monthly</changefreq>
    </url>
@foreach ($posts as $post)
    <url>
        <loc>{{ route('blog.show', $post->slug) }}</loc>
        <lastmod>{{ $post->updated_at->toDateString() }}</lastmod>
        <changefreq>monthly</changefreq>
    </url>
@endforeach
</urlset>
