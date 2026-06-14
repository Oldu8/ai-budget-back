@extends('admin.layout')

@section('content')
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-semibold">Articles</h1>
            <p class="text-sm text-gray-500 mt-1">{{ $posts->total() }} {{ \Illuminate\Support\Str::plural('article', $posts->total()) }}</p>
        </div>
        <a href="{{ route('admin.posts.create') }}" class="inline-flex items-center gap-2 px-4 py-2 rounded-md bg-blue-600 text-white text-sm font-medium hover:bg-blue-700 transition">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
            New article
        </a>
    </div>

    @if (session('status'))
        <div class="mb-4 px-4 py-3 rounded-md bg-green-50 border border-green-200 text-green-800 text-sm">{{ session('status') }}</div>
    @endif

    @if ($posts->isEmpty())
        <div class="bg-white rounded-lg border border-gray-200 shadow-sm py-16 px-6 text-center">
            <div class="mx-auto w-12 h-12 rounded-full bg-gray-100 flex items-center justify-center text-gray-400 mb-4">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 20H5a2 2 0 01-2-2V6a2 2 0 012-2h10a2 2 0 012 2v1m0 0a2 2 0 012 2v9a2 2 0 01-2 2m0-13h-3m-2 4h.01M9 16h.01M9 12h.01M9 8h.01"/></svg>
            </div>
            <h3 class="text-sm font-medium text-gray-900">No articles yet</h3>
            <p class="text-sm text-gray-500 mt-1 mb-5">Write your first post to start building the blog.</p>
            <a href="{{ route('admin.posts.create') }}" class="inline-flex items-center gap-2 px-4 py-2 rounded-md bg-blue-600 text-white text-sm font-medium hover:bg-blue-700 transition">
                New article
            </a>
        </div>
    @else
        <div class="bg-white rounded-lg border border-gray-200 shadow-sm overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 border-b border-gray-200 text-gray-500">
                        <tr>
                            <th class="text-left px-4 py-3 font-medium">Article</th>
                            <th class="text-left px-4 py-3 font-medium">Lang</th>
                            <th class="text-left px-4 py-3 font-medium">Status</th>
                            <th class="text-left px-4 py-3 font-medium">Published</th>
                            <th class="text-left px-4 py-3 font-medium">Updated</th>
                            <th class="text-right px-4 py-3 font-medium"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($posts as $post)
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-3">
                                        @if ($post->coverUrl())
                                            <img src="{{ $post->coverUrl() }}" alt="" class="w-14 h-10 rounded object-cover border border-gray-200 shrink-0">
                                        @else
                                            <div class="w-14 h-10 rounded bg-gray-100 border border-gray-200 flex items-center justify-center text-gray-400 shrink-0">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14M4 6h16M4 6a2 2 0 00-2 2v10a2 2 0 002 2h16a2 2 0 002-2V8a2 2 0 00-2-2"/></svg>
                                            </div>
                                        @endif
                                        <div class="min-w-0">
                                            <a href="{{ route('admin.posts.edit', $post) }}" class="font-medium text-gray-900 hover:text-blue-600 line-clamp-1">{{ $post->title }}</a>
                                            <div class="text-xs text-gray-400 font-mono truncate">/blog/{{ $post->slug }}</div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-3 uppercase text-gray-600">{{ $post->locale }}</td>
                                <td class="px-4 py-3">
                                    @if ($post->status === \App\Models\Post::STATUS_PUBLISHED)
                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                            <span class="w-1.5 h-1.5 rounded-full bg-green-500"></span> Published
                                        </span>
                                    @else
                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-600">
                                            <span class="w-1.5 h-1.5 rounded-full bg-gray-400"></span> Draft
                                        </span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-gray-600">{{ $post->published_at?->format('M d, Y') ?? '—' }}</td>
                                <td class="px-4 py-3 text-gray-600">{{ $post->updated_at->format('M d, Y') }}</td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center justify-end gap-3">
                                        @if ($post->isPublished() && $post->locale === 'en')
                                            <a href="{{ route('blog.show', $post->slug) }}" target="_blank" rel="noopener" class="text-gray-400 hover:text-gray-700" title="View live">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                                            </a>
                                        @endif
                                        <a href="{{ route('admin.posts.edit', $post) }}" class="text-blue-600 hover:underline font-medium">Edit</a>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($posts->hasPages())
                <div class="px-4 py-3 border-t border-gray-200">
                    {{ $posts->links() }}
                </div>
            @endif
        </div>
    @endif
@endsection
