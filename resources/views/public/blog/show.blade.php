@extends('public.layout')

@push('head')
    <script type="application/ld+json">
    @php
        $ld = [
            '@context' => 'https://schema.org',
            '@type' => 'Article',
            'headline' => $post->title,
            'author' => ['@type' => 'Organization', 'name' => config('app.name')],
            'description' => $post->metaDescriptionValue(),
            'inLanguage' => $locale,
            'datePublished' => optional($post->published_at)->toIso8601String(),
            'dateModified' => optional($post->updated_at)->toIso8601String(),
            'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $canonical],
            'publisher' => [
                '@type' => 'Organization',
                'name' => config('app.name'),
                'url' => url('/'),
            ],
        ];
        if ($post->coverUrl()) { $ld['image'] = [$post->coverUrl()]; }
    @endphp
    {!! json_encode($ld, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}
    </script>
    <script type="application/ld+json">
    {!! json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'BreadcrumbList',
        'itemListElement' => [
            ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => url('/')],
            ['@type' => 'ListItem', 'position' => 2, 'name' => __('blog.nav_blog'), 'item' => route('blog.index')],
            ['@type' => 'ListItem', 'position' => 3, 'name' => $post->title, 'item' => $canonical],
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}
    </script>
@endpush

@section('content')
    <main class="pt-[72px] pb-section-padding-mobile md:pb-[120px]">
        {{-- Breadcrumb & header --}}
        <section class="px-gutter pt-16 md:pt-24 pb-12">
            <div class="max-w-[1200px] mx-auto">
                <nav class="flex items-center gap-2 text-label-sm font-label-sm text-on-surface-variant mb-8 uppercase tracking-wider">
                    <a class="hover:text-primary transition-colors" href="{{ url('/') }}">Home</a>
                    <span class="material-symbols-outlined text-[14px]">chevron_right</span>
                    <a class="hover:text-primary transition-colors" href="{{ route('blog.index') }}">@lang('blog.nav_blog')</a>
                    <span class="material-symbols-outlined text-[14px]">chevron_right</span>
                    <span class="text-primary font-bold line-clamp-1">{{ $post->title }}</span>
                </nav>
                <div class="content-canvas">
                    <h1 class="font-display-lg-mobile md:font-display-lg text-display-lg-mobile md:text-display-lg text-primary mb-8">
                        {{ $post->title }}
                    </h1>
                    <div class="flex flex-wrap items-center gap-6 border-b border-outline-variant pb-8 mb-12">
                        <div class="flex items-center gap-2 text-on-surface-variant">
                            <span class="material-symbols-outlined text-[20px]">calendar_today</span>
                            <time datetime="{{ $post->published_at->toDateString() }}" class="font-body-md text-body-md">{{ $post->published_at->translatedFormat('M d, Y') }}</time>
                        </div>
                        <div class="flex items-center gap-2 text-on-surface-variant">
                            <span class="material-symbols-outlined text-[20px]">schedule</span>
                            <span class="font-body-md text-body-md">@lang('blog.min_read', ['count' => $post->readingTimeMinutes()])</span>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        {{-- Cover image --}}
        @if ($post->coverUrl())
            <section class="px-gutter mb-16 md:mb-24">
                <div class="max-w-[1200px] mx-auto">
                    <div class="relative aspect-[1.5] w-full overflow-hidden rounded-[32px] shadow-2xl">
                        <img alt="{{ $post->title }}" class="w-full h-full object-cover" src="{{ $post->coverUrl() }}" />
                    </div>
                </div>
            </section>
        @endif

        {{-- Body --}}
        <article class="px-gutter">
            <div class="content-canvas">
                <div class="article-body">
                    {!! $post->body !!}
                </div>

                {{-- CTA box --}}
                <div class="bg-secondary-container/30 border border-secondary-container rounded-[24px] my-16 min-h-[200px] flex flex-col items-center justify-center gap-6 text-center px-8">
                    <h3 class="font-headline-lg text-headline-lg text-primary">@lang('blog.cta_title')</h3>
                    <a href="https://apps.apple.com/app/id6795589223" onclick="typeof gtag === 'function' && gtag('event', 'app_store_click', { event_category: 'blog_cta' })">
                        <img src="{{ asset('badges/black.svg') }}" alt="Download on the App Store" class="h-14 w-[168px]">
                    </a>
                </div>

                <div class="pt-8 border-t border-outline-variant flex justify-between items-center">
                    <a class="flex items-center gap-2 text-primary font-bold hover:gap-4 transition-all" href="{{ route('blog.index') }}">
                        <span class="material-symbols-outlined">arrow_back</span>
                        @lang('blog.back_to_blog')
                    </a>
                </div>
            </div>
        </article>

        {{-- Read next --}}
        @if ($related->isNotEmpty())
            <section class="px-gutter mt-section-padding-mobile md:mt-[120px]">
                <div class="max-w-[1200px] mx-auto">
                    <h2 class="font-headline-lg text-headline-lg text-primary mb-12">@lang('blog.read_next')</h2>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                        @foreach ($related as $item)
                            <a href="{{ route('blog.show', $item->slug) }}" class="group">
                                <div class="aspect-[16/9] bg-surface-container-high rounded-2xl overflow-hidden mb-6">
                                    @if ($item->coverUrl())
                                        <img alt="{{ $item->title }}" loading="lazy" class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500" src="{{ $item->coverUrl() }}" />
                                    @else
                                        <div class="w-full h-full bg-primary/5 group-hover:bg-primary/10 transition-colors flex items-center justify-center">
                                            <span class="material-symbols-outlined text-primary text-[48px]">savings</span>
                                        </div>
                                    @endif
                                </div>
                                <h3 class="font-headline-md text-headline-md text-primary mt-2 group-hover:text-secondary transition-colors line-clamp-2">{{ $item->title }}</h3>
                                @if ($item->excerpt)
                                    <p class="font-body-md text-body-md text-on-surface-variant mt-2 line-clamp-2">{{ $item->excerpt }}</p>
                                @endif
                            </a>
                        @endforeach
                    </div>
                </div>
            </section>
        @endif
    </main>

    {{-- Back to top --}}
    <button id="to-top" aria-label="Back to top"
            class="fixed bottom-20 md:bottom-6 right-6 z-40 w-12 h-12 rounded-full cursor-pointer bg-primary text-on-primary shadow-lg flex items-center justify-center opacity-0 pointer-events-none transition-opacity duration-300 hover:brightness-110">
        <span class="material-symbols-outlined">arrow_upward</span>
    </button>
    <script>
        (function () {
            const btn = document.getElementById('to-top');
            const toggle = () => {
                if (window.scrollY > 400) {
                    btn.classList.remove('opacity-0', 'pointer-events-none');
                } else {
                    btn.classList.add('opacity-0', 'pointer-events-none');
                }
            };
            window.addEventListener('scroll', toggle, { passive: true });
            btn.addEventListener('click', () => window.scrollTo({ top: 0, behavior: 'smooth' }));
            toggle();
        })();
    </script>
@endsection
