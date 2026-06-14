<!doctype html>
<html class="scroll-smooth" lang="{{ $locale ?? app()->getLocale() }}">
<head>
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1.0" name="viewport" />

    <title>{{ ($title ?? config('app.name')) }} — {{ config('app.name') }}</title>
    @isset($metaDescription)
        <meta name="description" content="{{ $metaDescription }}" />
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
                        "error": "#ba1a1a",
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
                        "section-padding-desktop": "120px", "section-padding-mobile": "64px",
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
        .material-symbols-outlined { font-variation-settings: "FILL" 0, "wght" 400, "GRAD" 0, "opsz" 24; vertical-align: middle; }
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
<body class="bg-background text-on-background selection:bg-secondary-container selection:text-on-secondary-container">
    {{-- TopNavBar --}}
    <header class="fixed top-0 w-full h-[72px] bg-surface/80 border-b border-outline/10 shadow-sm z-50" style="backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px);">
        <div class="flex justify-between items-center px-gutter max-w-[1200px] mx-auto w-full h-full">
            <a href="{{ route('blog.index') }}" class="text-headline-md font-headline-md text-primary flex items-center gap-2">
                <span class="material-symbols-outlined text-primary" style="font-variation-settings: 'FILL' 1">account_balance_wallet</span>
                {{ config('app.name') }}
            </a>
            <nav class="hidden md:flex items-center gap-8">
                <a class="font-label-lg text-label-lg text-on-surface-variant hover:text-primary transition-colors" href="#">@lang('blog.nav_features')</a>
                <a class="font-label-lg text-label-lg text-primary font-bold border-b-2 border-primary" href="{{ route('blog.index') }}">@lang('blog.nav_blog')</a>
                <a class="font-label-lg text-label-lg text-on-surface-variant hover:text-primary transition-colors" href="#">@lang('blog.nav_faq')</a>
            </nav>
            <div class="flex items-center gap-4">
                <a href="#" class="bg-primary-container text-on-primary-container px-6 py-2 rounded-full font-label-lg text-label-lg hover:brightness-110 active:scale-95 transition-all">
                    @lang('blog.get_started')
                </a>
            </div>
        </div>
    </header>

    @yield('content')

    {{-- Footer --}}
    <footer class="w-full py-section-padding-mobile md:py-[80px] bg-primary text-on-primary">
        <div class="grid grid-cols-2 md:grid-cols-4 gap-12 px-gutter max-w-[1200px] mx-auto w-full">
            <div class="col-span-2 md:col-span-1 space-y-6">
                <div class="text-headline-md font-headline-md text-white">{{ config('app.name') }}</div>
                <p class="text-on-primary/70 font-body-md text-body-md max-w-xs">@lang('blog.footer_tagline')</p>
            </div>
            <div class="space-y-4">
                <div class="font-bold text-white">@lang('blog.footer_product')</div>
                <ul class="space-y-2">
                    <li><a class="text-on-primary/70 hover:text-secondary-fixed transition-colors" href="#">@lang('blog.nav_features')</a></li>
                    <li><a class="text-on-primary/70 hover:text-secondary-fixed transition-colors" href="#">@lang('blog.footer_pricing')</a></li>
                </ul>
            </div>
            <div class="space-y-4">
                <div class="font-bold text-white">@lang('blog.footer_company')</div>
                <ul class="space-y-2">
                    <li><a class="text-on-primary/70 hover:text-secondary-fixed transition-colors" href="{{ route('blog.index') }}">@lang('blog.nav_blog')</a></li>
                    <li><a class="text-on-primary/70 hover:text-secondary-fixed transition-colors" href="#">@lang('blog.footer_about')</a></li>
                </ul>
            </div>
            <div class="space-y-4">
                <div class="font-bold text-white">@lang('blog.footer_legal')</div>
                <ul class="space-y-2">
                    <li><a class="text-on-primary/70 hover:text-secondary-fixed transition-colors" href="#">@lang('blog.footer_privacy')</a></li>
                    <li><a class="text-on-primary/70 hover:text-secondary-fixed transition-colors" href="#">@lang('blog.footer_terms')</a></li>
                </ul>
            </div>
        </div>
        <div class="max-w-[1200px] mx-auto px-gutter mt-16 pt-8 border-t border-white/10 text-on-primary/50 text-label-sm">
            © {{ date('Y') }} {{ config('app.name') }}. @lang('blog.footer_rights')
        </div>
    </footer>

    <script>
        window.addEventListener("scroll", () => {
            const header = document.querySelector("header");
            if (window.scrollY > 20) header.classList.add("shadow-md");
            else header.classList.remove("shadow-md");
        });
    </script>
</body>
</html>
