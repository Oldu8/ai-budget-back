@extends('public.layout')

@php
    $title = 'Privacy Policy';
    $metaDescription = 'How Vtrata collects, uses, and protects your personal and financial data.';
    $canonical = route('privacy');
@endphp

@section('content')
    <main class="pt-[120px] md:pt-[150px] pb-section-padding-mobile md:pb-section-padding-desktop">
        {{-- Termly's export brings its own title, "last updated", and styles, so we only
             provide a centered, readable container. --}}
        <div class="content-canvas px-gutter">
            @include('public.legal.privacy')
        </div>
    </main>
@endsection
