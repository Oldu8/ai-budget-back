<!doctype html>
<html class="scroll-smooth" lang="{{ $locale ?? app()->getLocale() }}">
<head>
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1.0" name="viewport" />

    {{-- Google Analytics 4 (gtag.js) — prod only; also serves as Search Console verification --}}
    @if($gaId = config('services.google_analytics.measurement_id'))
        <script async src="https://www.googletagmanager.com/gtag/js?id={{ $gaId }}"></script>
        <script>
            window.dataLayer = window.dataLayer || [];
            function gtag(){dataLayer.push(arguments);}
            gtag('js', new Date());
            gtag('config', '{{ $gaId }}');
        </script>
    @endif

    {{-- Favicons / app icons (Vtrata brand) --}}
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any" />
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml" />
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32.png') }}" />
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16.png') }}" />
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}" />
    <link rel="manifest" href="{{ asset('site.webmanifest') }}" />
    <meta name="theme-color" content="#002422" />

    <title>{{ ($title ?? config('app.name')) }} — {{ config('app.name') }}</title>
    @isset($metaDescription)
        <meta name="description" content="{{ $metaDescription }}" />
    @endisset
    @isset($metaKeywords)
        <meta name="keywords" content="{{ $metaKeywords }}" />
    @endisset

    <link rel="canonical" href="{{ $canonical ?? url()->current() }}" />

    {{-- Open Graph / Twitter --}}
    <meta property="og:type" content="{{ $ogType ?? 'website' }}" />
    <meta property="og:title" content="{{ $title ?? config('app.name') }}" />
    @isset($metaDescription)<meta property="og:description" content="{{ $metaDescription }}" />@endisset
    <meta property="og:url" content="{{ $canonical ?? url()->current() }}" />
    @isset($ogImage)<meta property="og:image" content="{{ $ogImage }}" />@endisset
    <meta name="twitter:card" content="{{ isset($ogImage) ? 'summary_large_image' : 'summary' }}" />

    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet" />
    <script id="tailwind-config">
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        "primary": "#002422",
                        "on-primary": "#ffffff",
                        "primary-container": "#003b39",
                        "on-primary-container": "#75a5a2",
                        "primary-fixed": "#baece8",
                        "primary-fixed-dim": "#9ed0cc",
                        "secondary": "#006a66",
                        "on-secondary": "#ffffff",
                        "secondary-container": "#9deee8",
                        "on-secondary-container": "#0c6e6a",
                        "secondary-fixed": "#a0f1eb",
                        "secondary-fixed-dim": "#84d4cf",
                        "on-secondary-fixed": "#00201e",
                        "tertiary": "#371606",
                        "tertiary-fixed": "#ffdbcd",
                        "tertiary-fixed-dim": "#f6b8a0",
                        "error": "#ba1a1a",
                        "error-container": "#ffdad6",
                        "background": "#f9f9f8",
                        "on-background": "#191c1c",
                        "surface": "#f9f9f8",
                        "on-surface": "#191c1c",
                        "surface-variant": "#e2e3e2",
                        "on-surface-variant": "#404848",
                        "surface-container-lowest": "#ffffff",
                        "surface-container-low": "#f3f4f3",
                        "surface-container": "#edeeed",
                        "surface-container-high": "#e7e8e7",
                        "surface-container-highest": "#e2e3e2",
                        "outline": "#707978",
                        "outline-variant": "#c0c8c7",
                    },
                    borderRadius: { DEFAULT: "0.25rem", lg: "0.5rem", xl: "0.75rem", full: "9999px" },
                    spacing: {
                        base: "8px", gutter: "24px", "container-max": "1200px",
                        /* Tightened vertical rhythm for a denser, calmer page */
                        "section-padding-desktop": "64px", "section-padding-mobile": "40px",
                    },
                    fontFamily: {
                        "headline-lg": ["Inter"], "headline-md": ["Inter"], "display-lg": ["Inter"],
                        "display-lg-mobile": ["Inter"], "body-lg": ["Inter"], "body-md": ["Inter"],
                        "label-lg": ["Inter"], "label-sm": ["Inter"],
                    },
                    fontSize: {
                        "display-lg": ["57px", { lineHeight: "64px", letterSpacing: "-0.02em", fontWeight: "800" }],
                        "display-lg-mobile": ["40px", { lineHeight: "48px", letterSpacing: "-0.02em", fontWeight: "800" }],
                        "headline-lg": ["32px", { lineHeight: "40px", fontWeight: "700" }],
                        "headline-md": ["28px", { lineHeight: "36px", fontWeight: "700" }],
                        "body-lg": ["18px", { lineHeight: "28px", fontWeight: "400" }],
                        "body-md": ["16px", { lineHeight: "24px", fontWeight: "400" }],
                        "label-lg": ["14px", { lineHeight: "20px", letterSpacing: "0.1px", fontWeight: "600" }],
                        "label-sm": ["12px", { lineHeight: "16px", fontWeight: "500" }],
                    },
                },
            },
        };
    </script>
    <style>
        body { background-color: #f9f9f8; color: #191c1c; -webkit-font-smoothing: antialiased; font-family: "Inter", sans-serif; }
        .glass-header { backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px); background: rgba(249, 249, 248, 0.8); border-bottom: 1px solid rgba(111, 121, 120, 0.1); }
        .feature-card-shadow { box-shadow: 0 10px 30px -10px rgba(0, 59, 57, 0.15); }
        .material-symbols-outlined { font-variation-settings: "FILL" 0, "wght" 400, "GRAD" 0, "opsz" 24; vertical-align: middle; }
        .app-frame { border: 12px solid #191c1c; border-radius: 44px; }
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
        @keyframes float { 0%,100% { transform: translateY(0); } 50% { transform: translateY(-10px); } }
        .floating-mockup { animation: float 6s ease-in-out infinite; }
        .content-canvas { max-width: 720px; margin-left: auto; margin-right: auto; }

        /* Styles for admin-authored (TinyMCE) article HTML */
        .article-body { color: #404848; }
        .article-body > * + * { margin-top: 2rem; }
        .article-body p { font-size: 18px; line-height: 28px; }
        .article-body h2 { font-size: 32px; line-height: 40px; font-weight: 700; color: #002422; padding-top: 1.5rem; }
        .article-body h3 { font-size: 28px; line-height: 36px; font-weight: 700; color: #002422; padding-top: 1rem; }
        .article-body h4 { font-size: 22px; line-height: 28px; font-weight: 700; color: #002422; }
        .article-body ul { list-style: disc; padding-left: 1.5rem; }
        .article-body ol { list-style: decimal; padding-left: 1.5rem; }
        .article-body li { font-size: 16px; line-height: 24px; }
        .article-body li + li { margin-top: 0.75rem; }
        .article-body a { color: #006a66; text-decoration: underline; }
        .article-body strong { color: #191c1c; }
        .article-body img { border-radius: 1rem; margin: 2rem auto; max-width: 100%; height: auto; }
        .article-body figure figcaption { text-align: center; margin-top: 1rem; font-size: 12px; font-style: italic; color: #707978; }
        .article-body blockquote { border-left: 4px solid #006a66; padding: 2rem; background: #f3f4f3; border-radius: 0.75rem; font-size: 28px; line-height: 36px; color: #002422; font-weight: 700; font-style: italic; }
    </style>
    @stack('head')
</head>
<body class="bg-background text-on-background font-body-md overflow-x-hidden selection:bg-secondary-container selection:text-on-secondary-container min-h-screen flex flex-col">

    {{-- ============ Header (shared by landing + blog) ============ --}}
    <header class="fixed top-0 w-full z-50 glass-header h-[72px]">
        <div class="max-w-container-max mx-auto px-gutter h-full flex justify-between items-center">
            <a href="{{ url('/') }}" class="flex items-center gap-2">
                <img src="{{ asset('favicon.svg') }}" alt="{{ config('app.name') }}" class="w-8 h-8 rounded-lg" />
                <span class="font-headline-md text-headline-md text-primary tracking-tight">{{ config('app.name') }}</span>
            </a>

            <nav class="hidden md:flex items-center gap-8">
                <a class="font-label-lg text-label-lg text-on-surface-variant hover:text-secondary transition-colors" href="{{ route('home') }}#features">Features</a>
                <a class="font-label-lg text-label-lg transition-colors {{ request()->routeIs('blog.*') ? 'text-primary font-bold border-b-2 border-primary' : 'text-on-surface-variant hover:text-secondary' }}" href="{{ route('blog.index') }}">Blog</a>
                <a class="font-label-lg text-label-lg text-on-surface-variant hover:text-secondary transition-colors" href="{{ route('home') }}#faq">FAQ</a>
            </nav>

            <div class="flex items-center gap-4 md:gap-6">
                <a href="{{ route('home') }}#download" class="bg-primary-container/80 text-white px-5 md:px-6 py-2.5 rounded-full font-label-lg text-label-lg hover:opacity-90 active:scale-95 transition-all shadow-sm whitespace-nowrap inline-flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-[18px]">schedule</span> Coming soon
                </a>
            </div>
        </div>
    </header>

    {{-- Grows to fill the viewport so the footer stays pinned to the bottom on short pages --}}
    <div class="flex-grow flex flex-col">
        @yield('content')
    </div>

    {{-- ============ Footer (shared by landing + blog) ============ --}}
    <footer class="bg-primary pt-section-padding-mobile md:pt-section-padding-desktop text-on-primary">
        <div class="max-w-container-max mx-auto px-gutter">
            <div class="grid grid-cols-2 md:grid-cols-4 gap-10 md:gap-12 mb-12">
                <div class="col-span-2 md:col-span-2">
                    <div class="flex items-center gap-2 mb-6">
                        <img src="{{ asset('favicon.svg') }}" alt="{{ config('app.name') }}" class="w-8 h-8 rounded-lg" />
                        <span class="font-headline-md text-headline-md text-on-primary tracking-tight">{{ config('app.name') }}</span>
                    </div>
                    <p class="text-on-primary/60 font-body-md mb-6 max-w-xs">
                        Making financial management a calm, automated experience for everyone.
                    </p>
                    <div class="flex gap-4">
                        <a class="text-on-primary/60 hover:text-white transition-colors" href="#"><span class="material-symbols-outlined">public</span></a>
                        <a class="text-on-primary/60 hover:text-white transition-colors" href="#"><span class="material-symbols-outlined">share</span></a>
                        <a class="text-on-primary/60 hover:text-white transition-colors" href="#"><span class="material-symbols-outlined">alternate_email</span></a>
                    </div>
                </div>
                <ul class="space-y-4 text-on-primary/60">
                    <li><a class="hover:text-white transition-colors" href="{{ route('home') }}#features">Features</a></li>
                    <li><a class="hover:text-white transition-colors" href="{{ route('home') }}#screens">Inside the app</a></li>
                    <li><a class="hover:text-white transition-colors" href="{{ route('home') }}#faq">FAQ</a></li>
                </ul>
                <ul class="space-y-4 text-on-primary/60">
                    <li><a class="hover:text-white transition-colors" href="{{ route('blog.index') }}">Blog</a></li>
                    <li><a class="hover:text-white transition-colors" href="{{ route('privacy') }}">Privacy Policy</a></li>
                    <li><a class="hover:text-white transition-colors" href="{{ route('terms') }}">Terms of Service</a></li>
                </ul>
            </div>
        </div>
        {{-- Full-width bottom bar: border spans the whole viewport, content stays centered --}}
        <div class="border-t border-on-primary/10">
            <div class="max-w-container-max mx-auto px-gutter py-8 flex flex-col md:flex-row justify-between items-center gap-4">
                <p class="font-label-sm text-label-sm text-on-primary/40">© {{ date('Y') }} {{ config('app.name') }}. All rights reserved.</p>
                <div class="flex gap-6">
                    <a class="font-label-sm text-label-sm text-on-primary/40 hover:text-white" href="{{ route('privacy') }}">Privacy Policy</a>
                    <a class="font-label-sm text-label-sm text-on-primary/40 hover:text-white" href="{{ route('terms') }}">Terms of Service</a>
                </div>
            </div>
        </div>
    </footer>

    <script>
        // Sticky header subtle shadow on scroll
        window.addEventListener("scroll", () => {
            const header = document.querySelector("header");
            if (!header) return;
            if (window.scrollY > 20) header.classList.add("shadow-md");
            else header.classList.remove("shadow-md");
        });

        // Generic carousel: scrolls the track by one visible item per click
        document.querySelectorAll("[data-carousel]").forEach((track) => {
            const root = track.closest("[data-carousel-root]") || document;
            const step = () => {
                const item = track.querySelector("[data-carousel-item]");
                if (!item) return track.clientWidth;
                const gap = parseFloat(getComputedStyle(track).columnGap || getComputedStyle(track).gap || "0") || 0;
                return item.getBoundingClientRect().width + gap;
            };
            root.querySelectorAll("[data-carousel-prev]").forEach((b) =>
                b.addEventListener("click", () => track.scrollBy({ left: -step(), behavior: "smooth" }))
            );
            root.querySelectorAll("[data-carousel-next]").forEach((b) =>
                b.addEventListener("click", () => track.scrollBy({ left: step(), behavior: "smooth" }))
            );
        });
    </script>
    @stack('scripts')
</body>
</html>
