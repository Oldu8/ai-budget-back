@extends('admin.layout')

@section('content')
    <a href="{{ route('admin.posts.index') }}" class="inline-flex items-center gap-1 text-sm text-gray-500 hover:text-gray-700 mb-4">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
        Back to articles
    </a>
    <h1 class="text-2xl font-semibold mb-6">New article</h1>
    @include('admin.posts._form')
@endsection
