@extends('public.layout')

@php
    $title = 'Terms of Service';
    $metaDescription = 'The terms and conditions for using the Vtrata app and services.';
    $canonical = route('terms');
@endphp

@section('content')
    <main class="pt-[120px] md:pt-[150px] pb-section-padding-mobile md:pb-section-padding-desktop">
        {{-- Termly's export brings its own title, "last updated", and styles, so we only
             provide a centered, readable container. Rendered raw from a plain .html file so Blade
             never parses the legal HTML's @ / {{ }} characters. To update: re-export from Termly
             and replace the contents of resources/views/public/legal/terms.html --}}
        <div class="content-canvas px-gutter">
            {!! file_get_contents(resource_path('views/public/legal/terms.html')) !!}
        </div>
    </main>
@endsection
