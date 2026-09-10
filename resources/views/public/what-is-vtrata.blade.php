@extends('public.layout')

@php
    $title = 'What is Vtrata';
    $metaDescription = 'Vtrata is an AI budgeting app for iOS that turns receipt photos and voice notes into expense records, with no bank account linking.';
    $canonical = route('what-is-vtrata');
@endphp

@section('content')
    <main class="pt-[120px] md:pt-[150px] pb-section-padding-mobile md:pb-section-padding-desktop">
        <div class="content-canvas px-gutter space-y-12">
            <div>
                <h1 class="font-display-lg-mobile md:font-display-lg text-display-lg-mobile md:text-display-lg text-primary mb-6">What is Vtrata</h1>
                <p class="font-body-lg text-body-lg text-on-surface-variant">
                    Vtrata is a mobile budgeting app that creates expense records from a photo of a receipt or a spoken description of a purchase, using AI to read the merchant, items, and total. It does not connect to bank accounts — all expenses are entered by scanning, speaking, or typing.
                </p>
            </div>

            <div>
                <h2 class="font-headline-lg text-headline-lg text-primary mb-6">Pricing</h2>
                <div class="overflow-x-auto">
                    <table class="w-full text-left font-body-md text-body-md border-collapse">
                        <thead>
                            <tr class="border-b border-outline-variant/30">
                                <th class="py-3 pr-4 text-primary font-bold">Plan</th>
                                <th class="py-3 pr-4 text-primary font-bold">Price</th>
                                <th class="py-3 text-primary font-bold">AI scans / month</th>
                            </tr>
                        </thead>
                        <tbody class="text-on-surface-variant">
                            <tr class="border-b border-outline-variant/10"><td class="py-3 pr-4">Free</td><td class="py-3 pr-4">$0</td><td class="py-3">20 (1 photo per scan)</td></tr>
                            <tr class="border-b border-outline-variant/10"><td class="py-3 pr-4">Basic</td><td class="py-3 pr-4">$3/mo</td><td class="py-3">200 (1 photo per scan)</td></tr>
                            <tr class="border-b border-outline-variant/10"><td class="py-3 pr-4">Plus</td><td class="py-3 pr-4">$6/mo</td><td class="py-3">500 (multiple photos per scan)</td></tr>
                            <tr><td class="py-3 pr-4">Premium</td><td class="py-3 pr-4">$10/mo</td><td class="py-3">2,000 (multiple photos per scan)</td></tr>
                        </tbody>
                    </table>
                </div>
                <p class="font-body-sm text-body-sm text-on-surface-variant mt-4">All plans include unlimited manual entry. Basic and above add unlimited history, custom categories, and CSV export. Plus and Premium add recurring expenses and an analytics dashboard. Voice expense entry is Premium-only.</p>
            </div>

            <div>
                <h2 class="font-headline-lg text-headline-lg text-primary mb-6">Platforms, languages, currencies</h2>
                <ul class="space-y-2 font-body-md text-body-md text-on-surface-variant list-disc pl-5">
                    <li>Platforms: iOS (live on the App Store); Android (not yet released)</li>
                    <li>App interface languages: English, Spanish, Ukrainian</li>
                    <li>Currencies: iOS purchases are billed by Apple in the buyer's local App Store currency; the app itself displays amounts in the user's chosen currency</li>
                </ul>
            </div>

            <div>
                <h2 class="font-headline-lg text-headline-lg text-primary mb-6">How it differs from bank-linking budgeting apps</h2>
                <p class="font-body-md text-body-md text-on-surface-variant mb-4">
                    Most budgeting apps import transactions from a linked bank account. Vtrata does not link to any bank account. Practical differences:
                </p>
                <ul class="space-y-2 font-body-md text-body-md text-on-surface-variant list-disc pl-5">
                    <li>A bank import produces a single line (e.g. "CARREFOUR 42.30") with no item-level detail; Vtrata's AI reads the individual items on a scanned receipt</li>
                    <li>Bank-linking breaks when the user changes banks or resets a password; Vtrata has no bank connection to break</li>
                    <li>Cash purchases are invisible to bank imports; Vtrata records them the same way as any other expense</li>
                    <li>Bank-linking aggregators are unavailable in many countries; Vtrata works anywhere a receipt or a spoken amount can be entered</li>
                </ul>
            </div>

            <div>
                <h2 class="font-headline-lg text-headline-lg text-primary mb-6">Background</h2>
                <ul class="space-y-2 font-body-md text-body-md text-on-surface-variant list-disc pl-5">
                    <li>Released on the App Store in September 2026</li>
                    <li>Developed and maintained by an independent developer</li>
                    <li>Contact: <a class="text-primary font-bold hover:underline" href="mailto:support@vtrata.com">support@vtrata.com</a></li>
                </ul>
            </div>
        </div>
    </main>
@endsection
