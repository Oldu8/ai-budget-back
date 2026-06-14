@extends('public.layout')

@section('content')
    <main class="pt-[150px] pb-section-padding-mobile md:pb-[120px]">
        <div class="max-w-[1200px] mx-auto px-gutter">
            {{-- Header --}}
            <header class="mb-16 text-center md:text-left">
                <h1 class="font-display-lg-mobile md:font-display-lg text-display-lg-mobile md:text-display-lg text-primary mb-4 tracking-tight">
                    @lang('blog.title')
                </h1>
                <p class="font-body-lg text-body-lg text-on-surface-variant max-w-2xl mx-auto md:mx-0">
                    @lang('blog.subtitle')
                </p>
            </header>

            {{-- Article grid --}}
            @if ($posts->isEmpty())
                <p class="text-center text-on-surface-variant py-24 font-body-lg text-body-lg">@lang('blog.empty')</p>
            @else
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-gutter">
                    @foreach ($posts as $post)
                        <article class="group bg-surface-container-lowest rounded-[12px] overflow-hidden flex flex-col border border-outline-variant/10 shadow-[0px_10px_30px_-15px_rgba(0,59,57,0.15)] hover:-translate-y-2 hover:shadow-[0px_20px_40px_-10px_rgba(0,59,57,0.18)] transition-all duration-300">
                            <a href="{{ route('blog.show', $post->slug) }}" class="block aspect-[1.75] overflow-hidden">
                                @if ($post->coverUrl())
                                    <img alt="{{ $post->title }}" loading="lazy"
                                         class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500"
                                         src="{{ $post->coverUrl() }}" />
                                @else
                                    <div class="w-full h-full bg-secondary-container flex items-center justify-center">
                                        <span class="material-symbols-outlined text-primary text-[48px] opacity-20">savings</span>
                                    </div>
                                @endif
                            </a>
                            <div class="p-6 flex flex-col flex-grow">
                                <div class="flex items-center gap-4 mb-3">
                                    <span class="font-label-sm text-label-sm text-outline">{{ $post->published_at->translatedFormat('M d, Y') }}</span>
                                    <span class="w-1 h-1 rounded-full bg-outline/40"></span>
                                    <span class="font-label-sm text-label-sm text-outline">@lang('blog.min_read', ['count' => $post->readingTimeMinutes()])</span>
                                </div>
                                <h2 class="font-headline-md text-[22px] leading-[28px] text-primary mb-3 line-clamp-2 group-hover:text-secondary transition-colors">
                                    <a href="{{ route('blog.show', $post->slug) }}">{{ $post->title }}</a>
                                </h2>
                                @if ($post->excerpt)
                                    <p class="font-body-md text-body-md text-on-surface-variant line-clamp-2 mb-6">{{ $post->excerpt }}</p>
                                @endif
                                <div class="mt-auto">
                                    <a href="{{ route('blog.show', $post->slug) }}" class="text-primary font-label-lg text-label-lg flex items-center gap-1 group/btn w-max">
                                        @lang('blog.read_article')
                                        <span class="material-symbols-outlined text-[18px] group-hover/btn:translate-x-1 transition-transform">arrow_forward</span>
                                    </a>
                                </div>
                            </div>
                        </article>
                    @endforeach
                </div>

                {{-- Pagination --}}
                @if ($posts->hasPages())
                    <nav class="mt-20 flex justify-center items-center gap-2">
                        <a href="{{ $posts->previousPageUrl() ?? '#' }}"
                           class="flex items-center justify-center p-2 rounded-full text-on-surface-variant hover:bg-surface-container transition-colors {{ $posts->onFirstPage() ? 'opacity-30 pointer-events-none' : '' }}">
                            <span class="material-symbols-outlined">chevron_left</span>
                            <span class="sr-only">@lang('blog.prev')</span>
                        </a>
                        <div class="flex items-center gap-1">
                            @foreach (range(1, $posts->lastPage()) as $page)
                                <a href="{{ $posts->url($page) }}"
                                   class="w-10 h-10 flex items-center justify-center rounded-full font-label-lg text-label-lg transition-all {{ $page === $posts->currentPage() ? 'bg-primary text-on-primary' : 'text-on-surface-variant hover:bg-surface-container' }}">
                                    {{ $page }}
                                </a>
                            @endforeach
                        </div>
                        <a href="{{ $posts->nextPageUrl() ?? '#' }}"
                           class="flex items-center justify-center p-2 rounded-full text-on-surface-variant hover:bg-surface-container transition-colors {{ ! $posts->hasMorePages() ? 'opacity-30 pointer-events-none' : '' }}">
                            <span class="material-symbols-outlined">chevron_right</span>
                            <span class="sr-only">@lang('blog.next')</span>
                        </a>
                    </nav>
                @endif
            @endif
        </div>
    </main>
@endsection
