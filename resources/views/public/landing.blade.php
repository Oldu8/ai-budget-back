@extends('public.layout')

@php
    // Bare title — layout.blade.php appends " — Vtrata" itself, so the brand doesn't go here too.
    $title = 'AI Receipt Scanner & Voice Expense Tracker';
    $metaDescription = 'Vtrata turns receipts and voice notes into an organized budget automatically. No bank linking. Free to start — download now on the App Store.';
    $ogImage = asset('images/og-image.png');
    $canonical = url('/');

    // Shared with the JSON-LD below (@push('head')) so the markup can't drift from what's rendered.
    $plans = [
        [
            'name' => 'Free', 'price' => '$0', 'highlight' => false,
            'features' => ['20 AI scans / month (1 photo)', 'Unlimited manual entries', '1 month of history', 'Basic categories'],
        ],
        [
            'name' => 'Basic', 'price' => '$3', 'highlight' => false,
            'features' => ['200 AI scans / month (1 photo)', 'Unlimited history', 'Custom categories', 'CSV export'],
        ],
        [
            'name' => 'Plus', 'price' => '$6', 'highlight' => true,
            'features' => ['500 AI scans / month (multi-photo)', 'Recurring expenses', 'Analytics dashboard', 'CSV export'],
        ],
        [
            'name' => 'Premium', 'price' => '$10', 'highlight' => false,
            'features' => ['2,000 AI scans / month (multi-photo)', 'Advanced analytics', 'Voice expense entry', 'CSV export'],
        ],
    ];

    $faqs = [
        ['Is my data private?', 'Your financial data is yours. We don\'t sell it to anyone, traffic between the app and our servers is encrypted over HTTPS, and you can delete your account and data whenever you want.'],
        ['How does AI receipt scanning work?', 'Take a photo of a receipt and the AI reads the merchant, date, total and individual items, then suggests categories. You always review the result and can edit anything before it is saved.'],
        ['Do I need to connect my bank account?', 'No. Vtrata doesn\'t link to bank accounts. You add expenses by scanning receipts, entering them manually, or — on the Premium plan — by voice message.'],
        ['Isn\'t scanning receipts more work than automatic bank import?', 'It\'s one extra step at checkout — but in return you get a full itemized breakdown instead of a vague line like "CARREFOUR 42.30". It also works with cash, foreign cards, and any country, which bank imports can\'t.'],
        ['Is Vtrata free?', 'Yes — the Free plan includes 20 AI receipt scans a month, no card required. Paid plans start at $3/month for higher limits and extra features.'],
        ['Can I export my data?', 'Yes. On paid plans you can export your transactions to CSV at any time.'],
        ['Is there a web or desktop version?', 'Not yet. Vtrata is currently available on iOS. Android is coming soon, and a web version may follow later.'],
    ];
@endphp

@push('head')
    <script type="application/ld+json">
    {!! json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'SoftwareApplication',
        'name' => 'Vtrata',
        'description' => $metaDescription,
        'operatingSystem' => 'iOS',
        'applicationCategory' => 'FinanceApplication',
        'url' => $canonical,
        'downloadUrl' => 'https://apps.apple.com/app/id6795589223',
        'offers' => array_map(fn ($plan) => [
            '@type' => 'Offer',
            'name' => $plan['name'],
            'price' => ltrim($plan['price'], '$'),
            'priceCurrency' => 'USD',
        ], $plans),
        // aggregateRating intentionally omitted until there are real App Store ratings to report.
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}
    </script>
    <script type="application/ld+json">
    {!! json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'FAQPage',
        'mainEntity' => array_map(fn ($faq) => [
            '@type' => 'Question',
            'name' => $faq[0],
            'acceptedAnswer' => ['@type' => 'Answer', 'text' => $faq[1]],
        ], $faqs),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}
    </script>
    <script type="application/ld+json">
    {!! json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'Organization',
        'name' => config('app.name'),
        'url' => url('/'),
        'logo' => asset('favicon.svg'),
        'email' => 'support@vtrata.com',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}
    </script>
@endpush

@section('content')
    <main class="pt-[72px]">
        {{-- ============ Hero ============ --}}
        <section class="relative overflow-hidden pt-10 pb-section-padding-mobile md:pt-16 md:pb-section-padding-desktop bg-gradient-to-br from-surface to-secondary-fixed/20">
            <div class="max-w-container-max mx-auto px-gutter grid grid-cols-1 lg:grid-cols-2 gap-10 lg:gap-12 items-center">
                <div class="z-10 text-center lg:text-left">
                    <h1 class="font-display-lg-mobile md:font-display-lg text-display-lg-mobile md:text-display-lg text-primary mb-5">
                        Snap a receipt.<br class="hidden md:block" /> Skip the spreadsheet.
                    </h1>
                    <p class="font-body-lg text-body-lg text-on-surface-variant mb-8 max-w-md mx-auto lg:mx-0">
                        AI reads every item on your receipt — or just say it out loud. No bank account, ever.
                    </p>
                    {{-- App store badges — Google Play stays disabled "Coming soon" until the app is published --}}
                    <div id="download" class="flex flex-wrap justify-center lg:justify-start items-center gap-3 scroll-mt-[88px]">
                        <a href="https://apps.apple.com/app/id6795589223" onclick="typeof gtag === 'function' && gtag('event', 'app_store_click', { event_category: 'landing_hero' })">
                            {{-- Explicit width+height (not h-full/w-auto): the SVG's own intrinsic
                                 ratio didn't get picked up by the browser inside a stretched flex
                                 item, so it rendered squashed. 162x54 matches the Google Play
                                 pill's real height here (measured), keeping the 2.9916:1 ratio. --}}
                            <img src="{{ asset('badges/black.svg') }}" alt="Download on the App Store" class="h-[54px] w-[162px]">
                        </a>
                        <div class="bg-primary text-on-primary px-6 py-3 rounded-full flex items-center gap-2 opacity-60 cursor-not-allowed select-none">
                            <span class="material-symbols-outlined" style="font-variation-settings: 'FILL' 1">play_arrow</span>
                            <span class="flex flex-col items-start leading-none">
                                <span class="text-[10px] font-label-sm uppercase opacity-80">Coming soon</span>
                                <span class="text-sm font-bold">Google Play</span>
                            </span>
                        </div>
                    </div>
                </div>
                <div class="relative flex justify-center lg:justify-end">
                    <div class="absolute -z-10 w-[400px] h-[400px] bg-secondary-fixed opacity-30 blur-[100px] rounded-full"></div>
                    <div class="app-frame w-[280px] md:w-[320px] aspect-[9/19.5] overflow-hidden bg-white shadow-2xl floating-mockup">
                        <img src="{{ asset('images/mein.webp') }}" alt="Vtrata home screen" class="w-full h-full object-cover" loading="eager" fetchpriority="high">
                    </div>
                </div>
            </div>
        </section>

        {{-- ============ Problem vs Solution ============ --}}
        <section class="py-section-padding-mobile md:py-section-padding-desktop">
            <div class="max-w-container-max mx-auto px-gutter">
                <h2 class="font-headline-lg text-headline-lg text-primary text-center mb-10">Bank imports vs. itemized receipts</h2>
                <div class="grid md:grid-cols-2 gap-10 md:gap-12 bg-surface-container rounded-3xl p-8 md:p-12">
                    <div class="space-y-6">
                        <div class="w-12 h-12 bg-error-container rounded-full flex items-center justify-center text-error">
                            <span class="material-symbols-outlined">close</span>
                        </div>
                        <h3 class="font-headline-lg text-headline-lg text-primary">Bank-linking apps</h3>
                        <ul class="space-y-4 text-on-surface-variant">
                            <li class="flex items-center gap-3"><span class="material-symbols-outlined text-error text-[20px]">warning</span> Just a line: "CARREFOUR 42.30" — no idea what you bought</li>
                            <li class="flex items-center gap-3"><span class="material-symbols-outlined text-error text-[20px]">warning</span> Breaks the moment you switch banks or reset a password</li>
                            <li class="flex items-center gap-3"><span class="material-symbols-outlined text-error text-[20px]">warning</span> Cash purchases never show up</li>
                            <li class="flex items-center gap-3"><span class="material-symbols-outlined text-error text-[20px]">warning</span> Doesn't work in a lot of countries</li>
                        </ul>
                    </div>
                    <div class="space-y-6 border-t md:border-t-0 md:border-l border-outline-variant/30 pt-8 md:pt-0 md:pl-12">
                        <div class="w-12 h-12 bg-secondary-container rounded-full flex items-center justify-center text-secondary">
                            <span class="material-symbols-outlined">check</span>
                        </div>
                        <h3 class="font-headline-lg text-headline-lg text-primary">Itemized, automatically</h3>
                        <ul class="space-y-4 text-on-surface-variant">
                            <li class="flex items-center gap-3"><span class="material-symbols-outlined text-secondary text-[20px]">verified</span> Snap a receipt — AI reads merchant, every item, and total in seconds</li>
                            <li class="flex items-center gap-3"><span class="material-symbols-outlined text-secondary text-[20px]">verified</span> Or just say it out loud — no typing, no linking</li>
                            <li class="flex items-center gap-3"><span class="material-symbols-outlined text-secondary text-[20px]">verified</span> Works with cash, foreign cards, any bank, any country</li>
                        </ul>
                    </div>
                </div>
            </div>
        </section>

        {{-- ============ Features grid ============ --}}
        <section class="py-section-padding-mobile md:py-section-padding-desktop bg-surface scroll-mt-[72px]" id="features">
            <div class="max-w-container-max mx-auto px-gutter">
                <div class="text-center mb-10">
                    <h2 class="font-headline-lg text-headline-lg text-primary mb-4">AI receipt scanning and voice expense tracking</h2>
                    <p class="font-body-md text-body-md text-on-surface-variant max-w-xl mx-auto">
                        Snap a receipt or say what you spent — Vtrata categorizes it, tracks it, and remembers it, automatically.
                    </p>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                    @php
                        $features = [
                            ['camera_enhance', 'AI receipt scanning', 'Snap a photo and Vtrata extracts merchant, date, total, and items instantly.'],
                            ['mic', 'Voice expense logging', 'Just say what you bought — Vtrata turns it into a categorized expense.'],
                            ['no_accounts', 'No connection needed', 'Log cash, a friend\'s card, or a receipt from your last trip abroad — nothing to connect, nothing that breaks when you switch banks.'],
                            ['account_balance_wallet', 'Budgets that stick', 'Recurring expenses, smart limits, and alerts that adapt to how you actually spend.'],
                        ];
                    @endphp
                    @foreach ($features as [$icon, $featureTitle, $text])
                        <div class="p-8 bg-surface-container-lowest rounded-2xl feature-card-shadow border border-outline-variant/10 hover:-translate-y-1 transition-transform">
                            <div class="w-12 h-12 bg-secondary-container rounded-full flex items-center justify-center text-secondary mb-6">
                                <span class="material-symbols-outlined">{{ $icon }}</span>
                            </div>
                            <h3 class="font-headline-md text-[20px] leading-7 font-bold text-primary mb-3">{{ $featureTitle }}</h3>
                            <p class="font-body-md text-body-md text-on-surface-variant">{{ $text }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        {{-- ============ Screenshot showcase (grid) ============ --}}
        <section class="py-section-padding-mobile md:py-section-padding-desktop bg-surface-container-low scroll-mt-[72px]" id="screens">
            <div class="max-w-container-max mx-auto px-gutter mb-10 text-center">
                <h2 class="font-headline-lg text-headline-lg text-primary">See how Vtrata tracks your spending</h2>
            </div>

            <div class="max-w-container-max mx-auto px-gutter">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-x-8 gap-y-12">
                    @php
                        $screens = [
                            ['scannec', 'AI receipt scan', 'Photograph a receipt and Vtrata reads the merchant, every item, and total in a few seconds.'],
                            ['sound', 'Voice expense entry', 'Say what you bought out loud — Vtrata turns it into a categorized expense instantly.'],
                            ['scan', 'Manual add', 'No receipt? Add an expense by hand in a couple of taps.'],
                            ['analytics', 'Spending analytics', 'See exactly where your money goes each month, broken down by category.'],
                            ['history', 'Transaction history', 'Browse your full spending history, searchable and organized by date.'],
                        ];
                    @endphp
                    @foreach ($screens as [$file, $label, $caption])
                        <div>
                            <div class="app-frame w-full max-w-[280px] mx-auto aspect-[9/19.5] overflow-hidden bg-white shadow-xl">
                                <img src="{{ asset('images/'.$file.'.webp') }}" alt="Vtrata app screen: {{ $label }}" class="w-full h-full object-cover" loading="lazy">
                            </div>
                            <p class="text-center mt-4 font-body-md text-body-md text-on-surface-variant max-w-[280px] mx-auto">{{ $caption }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        {{--
        ============ Testimonials (hidden until app launch — no real users/reviews yet) ============
        <section class="py-section-padding-mobile md:py-section-padding-desktop bg-surface">
            <div class="max-w-container-max mx-auto px-gutter">
                <h2 class="font-headline-lg text-headline-lg text-primary text-center mb-10">What our users say</h2>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6 md:gap-8">
                    @php
                        $reviews = [
                            ['Finally, a budgeting app that doesn\'t feel like a part-time job. The AI scanning is scarily accurate.', 'Sarah Jenkins', 'Freelance Designer', 'SJ'],
                            ['I\'ve saved nearly $400 in three months just by seeing where the "hidden" costs were. Brilliant UI.', 'Mark Roberts', 'Software Engineer', 'MR'],
                            ['The visuals are stunning. It actually makes me want to check my spending. Highly recommended.', 'Elena Torres', 'Marketing Manager', 'ET'],
                        ];
                    @endphp
                    @foreach ($reviews as [$quote, $name, $role, $initials])
                        <div class="p-8 bg-surface-container rounded-2xl border border-outline-variant/10">
                            <div class="flex text-secondary mb-4">
                                @for ($i = 0; $i < 5; $i++)
                                    <span class="material-symbols-outlined" style="font-variation-settings: 'FILL' 1">star</span>
                                @endfor
                            </div>
                            <p class="font-body-md text-body-md text-on-surface mb-8 italic">"{{ $quote }}"</p>
                            <div class="flex items-center gap-4">
                                <div class="w-12 h-12 rounded-full bg-secondary-container text-on-secondary-container flex items-center justify-center font-bold border-2 border-white">{{ $initials }}</div>
                                <div>
                                    <div class="font-label-lg text-label-lg text-primary">{{ $name }}</div>
                                    <div class="font-label-sm text-label-sm text-on-surface-variant">{{ $role }}</div>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>
        --}}

        {{-- ============ Pricing ============ --}}
        {{--
            Prices confirmed by product owner to match Stripe (2026-09): Basic $3, Plus $6, Premium $10/mo.
            Feature/quota bullets come straight from database/data/plans.php (live-enforced via
            FeatureAccessService). If Stripe prices change, update the `$plans` array below to match.
        --}}
        <section class="py-section-padding-mobile md:py-section-padding-desktop bg-surface-container/40 scroll-mt-[72px]" id="pricing">
            <div class="max-w-container-max mx-auto px-gutter">
                <div class="text-center mb-10">
                    <h2 class="font-headline-lg text-headline-lg text-primary mb-4">Simple, transparent pricing</h2>
                    <p class="font-body-md text-body-md text-on-surface-variant max-w-xl mx-auto">
                        Start free, no card required. Upgrade anytime as your tracking needs grow.
                    </p>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                    @foreach ($plans as $plan)
                        <div class="p-8 rounded-2xl border {{ $plan['highlight'] ? 'bg-primary text-on-primary border-primary shadow-lg' : 'bg-surface-container-lowest border-outline-variant/10' }}">
                            @if ($plan['highlight'])
                                <div class="text-[10px] font-label-sm uppercase tracking-wider text-on-primary/70 mb-2">Most popular</div>
                            @endif
                            <h3 class="font-headline-md text-[20px] leading-7 font-bold {{ $plan['highlight'] ? 'text-on-primary' : 'text-primary' }} mb-2">{{ $plan['name'] }}</h3>
                            <div class="mb-6">
                                <span class="font-display-lg-mobile text-[32px] font-extrabold {{ $plan['highlight'] ? 'text-on-primary' : 'text-primary' }}">{{ $plan['price'] }}</span>
                                <span class="font-body-md {{ $plan['highlight'] ? 'text-on-primary/70' : 'text-on-surface-variant' }}">/mo</span>
                            </div>
                            <ul class="space-y-3">
                                @foreach ($plan['features'] as $feature)
                                    <li class="flex items-start gap-2 font-body-md text-body-md {{ $plan['highlight'] ? 'text-on-primary/90' : 'text-on-surface-variant' }}">
                                        <span class="material-symbols-outlined text-[18px] mt-0.5 {{ $plan['highlight'] ? 'text-on-primary' : 'text-secondary' }}">check</span>
                                        {{ $feature }}
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        {{-- ============ FAQ ============ --}}
        <section class="py-section-padding-mobile md:py-section-padding-desktop bg-surface scroll-mt-[72px]" id="faq">
            <div class="max-w-3xl mx-auto px-gutter">
                <h2 class="font-headline-lg text-headline-lg text-primary text-center mb-10">Common questions</h2>
                <div class="space-y-4">
                    @foreach ($faqs as [$q, $a])
                        <details class="group border-b border-outline-variant/30 pb-4">
                            <summary class="w-full flex justify-between items-center py-4 text-left cursor-pointer list-none">
                                <span class="font-headline-md text-[18px] leading-6 font-bold text-primary group-hover:text-secondary transition-colors">{{ $q }}</span>
                                <span class="material-symbols-outlined transition-transform duration-300 group-open:rotate-180">expand_more</span>
                            </summary>
                            <div class="pt-2 pb-2 text-on-surface-variant font-body-md">{{ $a }}</div>
                        </details>
                    @endforeach
                </div>
            </div>
        </section>

        {{-- ============ Final CTA ============ --}}
        <section class="py-section-padding-mobile md:py-section-padding-desktop bg-primary-container relative overflow-hidden">
            <div class="max-w-container-max mx-auto px-gutter relative z-10 flex flex-col items-center text-center">
                <h2 class="font-display-lg-mobile md:font-display-lg text-display-lg-mobile md:text-display-lg text-white mb-6">Start tracking your spending with Vtrata</h2>
                <p class="font-body-lg text-body-lg text-on-primary-container mb-10 max-w-lg">
                    Available now on the App Store — no spreadsheets, no bank linking, no manual sorting. Android is coming soon.
                </p>
                <div class="flex flex-col sm:flex-row justify-center items-center gap-4">
                    <a href="https://apps.apple.com/app/id6795589223" onclick="typeof gtag === 'function' && gtag('event', 'app_store_click', { event_category: 'landing_final_cta' })">
                        {{-- 185x62 matches the Google Play pill's real height here (measured;
                             this section uses more padding than the hero, hence the bigger pill). --}}
                        <img src="{{ asset('badges/white.svg') }}" alt="Download on the App Store" class="h-[62px] w-[185px]">
                    </a>
                    <div class="bg-surface-container-lowest text-primary px-8 py-4 rounded-full flex items-center justify-center gap-3 opacity-70 cursor-not-allowed select-none">
                        <span class="material-symbols-outlined" style="font-variation-settings: 'FILL' 1">play_arrow</span>
                        <span class="flex flex-col items-start leading-none">
                            <span class="text-[10px] font-label-sm uppercase opacity-70">Coming soon</span>
                            <span class="text-sm font-bold">Google Play</span>
                        </span>
                    </div>
                </div>
            </div>
            <div class="absolute inset-0 opacity-10 pointer-events-none">
                <div class="absolute top-0 right-0 w-[600px] h-[600px] bg-secondary blur-[150px] -translate-y-1/2 translate-x-1/2"></div>
                <div class="absolute bottom-0 left-0 w-[400px] h-[400px] bg-secondary blur-[100px] translate-y-1/2 -translate-x-1/2"></div>
            </div>
        </section>
    </main>
@endsection
