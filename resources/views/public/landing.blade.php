@extends('public.layout')

@php
    $title = 'AI Budget App — Scan Receipts & Track Spending';
    $metaDescription = 'SnapSpend is an AI budgeting app that scans your receipts, logs expenses by voice, and tracks spending automatically. Get clear on your money — start free.';
    $metaKeywords = 'budget app, AI budget app, receipt scanner app, expense tracker, spending tracker, personal finance app, money management, track expenses, budgeting app';
    $canonical = url('/');
@endphp

@section('content')
    <main class="pt-[72px]">
        {{-- ============ Hero ============ --}}
        <section class="relative overflow-hidden pt-10 pb-section-padding-mobile md:pt-16 md:pb-section-padding-desktop bg-gradient-to-br from-surface to-secondary-fixed/20">
            <div class="max-w-container-max mx-auto px-gutter grid grid-cols-1 lg:grid-cols-2 gap-10 lg:gap-12 items-center">
                <div class="z-10 text-center lg:text-left">
                    <h1 class="font-display-lg-mobile md:font-display-lg text-display-lg-mobile md:text-display-lg text-primary mb-5">
                        Your money,<br class="hidden md:block" /> finally clear
                    </h1>
                    <p class="font-body-lg text-body-lg text-on-surface-variant mb-8 max-w-md mx-auto lg:mx-0">
                        Take control of your finances with AI-powered categorization and beautiful, intuitive insights.
                    </p>
                    {{-- App store buttons (CSS-based; swap for official badge images when ready) --}}
                    <div id="download" class="flex flex-wrap justify-center lg:justify-start gap-3 scroll-mt-[88px]">
                        <a href="#" class="bg-primary text-on-primary px-6 py-3 rounded-full flex items-center gap-2 hover:opacity-90 active:scale-95 transition-all">
                            <span class="material-symbols-outlined" style="font-variation-settings: 'FILL' 1">apps</span>
                            <span class="flex flex-col items-start leading-none">
                                <span class="text-[10px] font-label-sm uppercase opacity-80">Download on the</span>
                                <span class="text-sm font-bold">App Store</span>
                            </span>
                        </a>
                        <a href="#" class="bg-primary text-on-primary px-6 py-3 rounded-full flex items-center gap-2 hover:opacity-90 active:scale-95 transition-all">
                            <span class="material-symbols-outlined" style="font-variation-settings: 'FILL' 1">play_arrow</span>
                            <span class="flex flex-col items-start leading-none">
                                <span class="text-[10px] font-label-sm uppercase opacity-80">Get it on</span>
                                <span class="text-sm font-bold">Google Play</span>
                            </span>
                        </a>
                    </div>
                </div>
                <div class="relative flex justify-center lg:justify-end">
                    <div class="absolute -z-10 w-[400px] h-[400px] bg-secondary-fixed opacity-30 blur-[100px] rounded-full"></div>
                    {{-- SCREENSHOT PLACEHOLDER — replace inner .screenshot-ph div with <img class="w-full h-full object-cover" src="..."> --}}
                    <div class="app-frame w-[280px] md:w-[320px] aspect-[9/19.5] overflow-hidden bg-white shadow-2xl floating-mockup">
                        <div class="screenshot-ph w-full h-full flex-col gap-2 text-primary/40">
                            <span class="material-symbols-outlined text-5xl">screenshot</span>
                            <span class="font-label-sm text-label-sm">App preview</span>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        {{-- ============ Problem vs Solution ============ --}}
        <section class="py-section-padding-mobile md:py-section-padding-desktop">
            <div class="max-w-container-max mx-auto px-gutter">
                <div class="grid md:grid-cols-2 gap-10 md:gap-12 bg-surface-container rounded-3xl p-8 md:p-12">
                    <div class="space-y-6">
                        <div class="w-12 h-12 bg-error-container rounded-full flex items-center justify-center text-error">
                            <span class="material-symbols-outlined">close</span>
                        </div>
                        <h3 class="font-headline-lg text-headline-lg text-primary">Messy spreadsheets</h3>
                        <ul class="space-y-4 text-on-surface-variant">
                            <li class="flex items-center gap-3"><span class="material-symbols-outlined text-error text-[20px]">warning</span> Manual data entry takes hours</li>
                            <li class="flex items-center gap-3"><span class="material-symbols-outlined text-error text-[20px]">warning</span> Broken formulas lead to errors</li>
                            <li class="flex items-center gap-3"><span class="material-symbols-outlined text-error text-[20px]">warning</span> Hard to visualize where money goes</li>
                        </ul>
                    </div>
                    <div class="space-y-6 border-t md:border-t-0 md:border-l border-outline-variant/30 pt-8 md:pt-0 md:pl-12">
                        <div class="w-12 h-12 bg-secondary-container rounded-full flex items-center justify-center text-secondary">
                            <span class="material-symbols-outlined">check</span>
                        </div>
                        <h3 class="font-headline-lg text-headline-lg text-primary">Calm tracking</h3>
                        <ul class="space-y-4 text-on-surface-variant">
                            <li class="flex items-center gap-3"><span class="material-symbols-outlined text-secondary text-[20px]">verified</span> Instant AI receipt categorization</li>
                            <li class="flex items-center gap-3"><span class="material-symbols-outlined text-secondary text-[20px]">verified</span> Beautiful, automated visual charts</li>
                            <li class="flex items-center gap-3"><span class="material-symbols-outlined text-secondary text-[20px]">verified</span> Real-time budget health alerts</li>
                        </ul>
                    </div>
                </div>
            </div>
        </section>

        {{-- ============ Features grid ============ --}}
        <section class="py-section-padding-mobile md:py-section-padding-desktop bg-surface scroll-mt-[72px]" id="features">
            <div class="max-w-container-max mx-auto px-gutter">
                <div class="text-center mb-10">
                    <h2 class="font-headline-lg text-headline-lg text-primary mb-4">Master your finances</h2>
                    <p class="font-body-md text-body-md text-on-surface-variant max-w-xl mx-auto">
                        Modern tools for modern money management, powered by intelligent automation.
                    </p>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                    @php
                        $features = [
                            ['camera_enhance', 'AI receipt scanning', 'Snap a photo and let our AI extract dates, amounts, and categories instantly.'],
                            ['category', 'Auto-categorization', 'Smart tagging learns your habits to organize transactions automatically.'],
                            ['bar_chart', 'Monthly overview', 'Crystal clear charts showing exactly how your spending evolves over time.'],
                            ['account_balance_wallet', 'Budgets that stick', 'Dynamic limits that adapt to your income and saving goals effortlessly.'],
                        ];
                    @endphp
                    @foreach ($features as [$icon, $featureTitle, $text])
                        <div class="p-8 bg-surface-container-lowest rounded-2xl feature-card-shadow border border-outline-variant/10 hover:-translate-y-1 transition-transform">
                            <div class="w-12 h-12 bg-secondary-container rounded-full flex items-center justify-center text-secondary mb-6">
                                <span class="material-symbols-outlined">{{ $icon }}</span>
                            </div>
                            <h4 class="font-headline-md text-[20px] leading-7 font-bold text-primary mb-3">{{ $featureTitle }}</h4>
                            <p class="font-body-md text-body-md text-on-surface-variant">{{ $text }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        {{-- ============ Screenshot showcase (carousel) ============ --}}
        <section class="py-section-padding-mobile md:py-section-padding-desktop bg-surface-container-low overflow-hidden scroll-mt-[72px]" id="screens" data-carousel-root>
            <div class="max-w-container-max mx-auto px-gutter mb-8 flex items-end justify-between gap-4">
                <h2 class="font-headline-lg text-headline-lg text-primary">Inside the app</h2>
                {{-- Desktop carousel controls --}}
                <div class="hidden md:flex gap-2">
                    <button type="button" data-carousel-prev aria-label="Previous screens"
                            class="w-11 h-11 rounded-full bg-surface-container-lowest border border-outline-variant/20 text-primary flex items-center justify-center hover:bg-surface-container active:scale-95 transition-all">
                        <span class="material-symbols-outlined">chevron_left</span>
                    </button>
                    <button type="button" data-carousel-next aria-label="Next screens"
                            class="w-11 h-11 rounded-full bg-surface-container-lowest border border-outline-variant/20 text-primary flex items-center justify-center hover:bg-surface-container active:scale-95 transition-all">
                        <span class="material-symbols-outlined">chevron_right</span>
                    </button>
                </div>
            </div>

            {{-- SCREENSHOT PLACEHOLDERS — replace each .screenshot-ph with a real <img> --}}
            <div class="max-w-container-max mx-auto px-gutter">
                <div data-carousel class="flex gap-6 overflow-x-auto snap-x snap-mandatory no-scrollbar scroll-smooth pb-2">
                    @foreach (['Home dashboard', 'Detailed analytics', 'Add expense', 'Transaction history', 'AI receipt scan', 'Budget limits'] as $label)
                        <div data-carousel-item class="snap-start shrink-0 basis-full md:basis-[calc((100%-3rem)/3)]">
                            <div class="app-frame w-full max-w-[280px] mx-auto aspect-[9/19.5] overflow-hidden bg-white shadow-xl">
                                <div class="screenshot-ph w-full h-full flex-col gap-2 text-primary/40">
                                    <span class="material-symbols-outlined text-4xl">screenshot</span>
                                    <span class="font-label-sm text-label-sm px-4 text-center">{{ $label }}</span>
                                </div>
                            </div>
                            <p class="text-center mt-4 font-label-lg text-label-lg">{{ $label }}</p>
                        </div>
                    @endforeach
                </div>

                {{-- Mobile carousel controls --}}
                <div class="flex md:hidden justify-center gap-3 mt-6">
                    <button type="button" data-carousel-prev aria-label="Previous screen"
                            class="w-11 h-11 rounded-full bg-surface-container-lowest border border-outline-variant/20 text-primary flex items-center justify-center active:scale-95 transition-all">
                        <span class="material-symbols-outlined">chevron_left</span>
                    </button>
                    <button type="button" data-carousel-next aria-label="Next screen"
                            class="w-11 h-11 rounded-full bg-surface-container-lowest border border-outline-variant/20 text-primary flex items-center justify-center active:scale-95 transition-all">
                        <span class="material-symbols-outlined">chevron_right</span>
                    </button>
                </div>
            </div>
        </section>

        {{-- ============ Testimonials ============ --}}
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
                                {{-- AVATAR PLACEHOLDER — swap for a real photo when available --}}
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

        {{-- ============ FAQ ============ --}}
        <section class="py-section-padding-mobile md:py-section-padding-desktop bg-surface scroll-mt-[72px]" id="faq">
            <div class="max-w-3xl mx-auto px-gutter">
                <h2 class="font-headline-lg text-headline-lg text-primary text-center mb-10">Common questions</h2>
                <div class="space-y-4">
                    @php
                        $faqs = [
                            ['Is my data private?', 'Your financial data is yours. We don\'t sell it to anyone, traffic between the app and our servers is encrypted over HTTPS, and you can delete your account and data whenever you want.'],
                            ['How does AI receipt scanning work?', 'Take a photo of a receipt and the AI reads the merchant, date, total and individual items, then suggests categories. You always review the result and can edit anything before it is saved.'],
                            ['Do I need to connect my bank account?', 'No. SnapSpend doesn\'t link to bank accounts. You add expenses by scanning receipts, entering them manually, or — on the Premium plan — by voice message.'],
                            ['Can I export my data?', 'Yes. On paid plans you can export your transactions to CSV at any time.'],
                            ['Is there a web or desktop version?', 'Not yet. SnapSpend is currently a mobile app for iOS and Android. A web version may come later.'],
                        ];
                    @endphp
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
                <h2 class="font-display-lg-mobile md:font-display-lg text-display-lg-mobile md:text-display-lg text-white mb-6">Ready for financial clarity?</h2>
                <p class="font-body-lg text-body-lg text-on-primary-container mb-10 max-w-lg">
                    Start tracking your spending in minutes — no spreadsheets, no manual sorting.
                </p>
                <div class="flex flex-col sm:flex-row justify-center gap-4">
                    <a href="#" class="bg-surface-container-lowest text-primary px-8 py-4 rounded-full flex items-center justify-center gap-3 hover:bg-white active:scale-95 transition-all">
                        <span class="material-symbols-outlined" style="font-variation-settings: 'FILL' 1">apps</span>
                        <span class="flex flex-col items-start leading-none">
                            <span class="text-[10px] font-label-sm uppercase opacity-70">Download on the</span>
                            <span class="text-sm font-bold">App Store</span>
                        </span>
                    </a>
                    <a href="#" class="bg-surface-container-lowest text-primary px-8 py-4 rounded-full flex items-center justify-center gap-3 hover:bg-white active:scale-95 transition-all">
                        <span class="material-symbols-outlined" style="font-variation-settings: 'FILL' 1">play_arrow</span>
                        <span class="flex flex-col items-start leading-none">
                            <span class="text-[10px] font-label-sm uppercase opacity-70">Get it on</span>
                            <span class="text-sm font-bold">Google Play</span>
                        </span>
                    </a>
                </div>
            </div>
            <div class="absolute inset-0 opacity-10 pointer-events-none">
                <div class="absolute top-0 right-0 w-[600px] h-[600px] bg-secondary blur-[150px] -translate-y-1/2 translate-x-1/2"></div>
                <div class="absolute bottom-0 left-0 w-[400px] h-[400px] bg-secondary blur-[100px] translate-y-1/2 -translate-x-1/2"></div>
            </div>
        </section>
    </main>
@endsection
