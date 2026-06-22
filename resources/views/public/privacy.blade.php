@extends('public.layout')

@php
    $title = 'Privacy Policy';
    $metaDescription = 'How SnapSpend collects, uses, and protects your personal and financial data.';
    $canonical = route('privacy');
@endphp

@section('content')
    <main class="pt-[120px] md:pt-[150px] pb-section-padding-mobile md:pb-section-padding-desktop">
        <div class="content-canvas px-gutter">
            <h1 class="font-display-lg-mobile md:font-display-lg text-display-lg-mobile md:text-display-lg text-primary mb-6">
                Privacy Policy
            </h1>
            <p class="font-body-md text-body-md text-on-surface-variant border-b border-outline-variant pb-8 mb-12">
                Last updated {{ date('F j, Y') }}
            </p>

            <div class="article-body">
                {{-- TODO: replace this placeholder with the real Privacy Policy content. --}}
                <p>Content coming soon.</p>
            </div>
        </div>
    </main>
@endsection
