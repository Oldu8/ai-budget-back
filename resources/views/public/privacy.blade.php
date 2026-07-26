@extends('public.layout')

@php
    $title = 'Privacy Policy';
    $metaDescription = 'How Vtrata collects, uses, and protects your personal and financial data.';
    $canonical = route('privacy');
@endphp

@section('content')
    <main class="pt-[120px] md:pt-[150px] pb-section-padding-mobile md:pb-section-padding-desktop">
        {{-- Termly's export brings its own title, "last updated", and styles, so we only
             provide a centered, readable container. Rendered raw from a plain .html file so Blade
             never parses the legal HTML's @ / {{ }} characters. To update: in Termly "Copy HTML"
             and replace the contents of resources/views/public/legal/privacy.html --}}
        <div class="content-canvas px-gutter legal-content">
            {!! file_get_contents(resource_path('views/public/legal/privacy.html')) !!}
        </div>
    </main>
@endsection
