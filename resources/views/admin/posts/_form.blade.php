@php
    $isEdit = $post->exists;
    $inputClass = 'w-full border border-gray-300 rounded-md px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition';
    $labelClass = 'block text-sm font-medium text-gray-700 mb-1';
@endphp

@if ($errors->any())
    <div class="mb-4 px-4 py-3 rounded-md bg-red-50 border border-red-200 text-red-800 text-sm">
        <ul class="list-disc list-inside space-y-0.5">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

@if (session('status'))
    <div class="mb-4 px-4 py-3 rounded-md bg-green-50 border border-green-200 text-green-800 text-sm">{{ session('status') }}</div>
@endif

<form method="POST"
      action="{{ $isEdit ? route('admin.posts.update', $post) : route('admin.posts.store') }}"
      enctype="multipart/form-data">
    @csrf
    @if ($isEdit)
        @method('PUT')
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 items-start">
        {{-- Main column --}}
        <div class="lg:col-span-2 space-y-6">
            <div class="bg-white rounded-lg border border-gray-200 shadow-sm p-6 space-y-5">
                <div>
                    <label for="title" class="{{ $labelClass }}">Title</label>
                    <input type="text" id="title" name="title" value="{{ old('title', $post->title) }}"
                           class="{{ $inputClass }}" required>
                </div>

                <div>
                    <label for="slug" class="{{ $labelClass }}">
                        Slug <span class="text-gray-400 font-normal">— optional, auto from title</span>
                    </label>
                    <div class="flex items-center">
                        <span class="inline-flex items-center px-3 py-2 text-sm text-gray-500 bg-gray-50 border border-r-0 border-gray-300 rounded-l-md">/blog/</span>
                        <input type="text" id="slug" name="slug" value="{{ old('slug', $post->slug) }}"
                               placeholder="my-article-url"
                               class="w-full border border-gray-300 rounded-r-md px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition">
                    </div>
                </div>

                <div>
                    <label for="excerpt" class="{{ $labelClass }}">
                        Excerpt <span class="text-gray-400 font-normal">— shown on cards & used as meta fallback</span>
                    </label>
                    <textarea id="excerpt" name="excerpt" rows="2" class="{{ $inputClass }}">{{ old('excerpt', $post->excerpt) }}</textarea>
                </div>

                <div>
                    <label for="body" class="{{ $labelClass }}">Body</label>
                    <textarea id="body" name="body" rows="20">{{ old('body', $post->body) }}</textarea>
                </div>
            </div>

            <div class="bg-white rounded-lg border border-gray-200 shadow-sm p-6 space-y-5">
                <div class="flex items-center gap-2">
                    <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                    <h2 class="text-sm font-semibold text-gray-700">SEO</h2>
                </div>
                <div>
                    <label for="meta_title" class="{{ $labelClass }}">Meta title</label>
                    <input type="text" id="meta_title" name="meta_title" value="{{ old('meta_title', $post->meta_title) }}" class="{{ $inputClass }}">
                    <p class="text-xs text-gray-400 mt-1">Leave empty to use the article title.</p>
                </div>
                <div>
                    <label for="meta_description" class="{{ $labelClass }}">Meta description</label>
                    <textarea id="meta_description" name="meta_description" rows="2" maxlength="255" class="{{ $inputClass }}">{{ old('meta_description', $post->meta_description) }}</textarea>
                    <p class="text-xs text-gray-400 mt-1">Recommended 150–160 characters. Falls back to the excerpt.</p>
                </div>
            </div>
        </div>

        {{-- Sidebar --}}
        <div class="space-y-6">
            <div class="bg-white rounded-lg border border-gray-200 shadow-sm p-6 space-y-5">
                <div>
                    <label for="status" class="{{ $labelClass }}">Status</label>
                    <select id="status" name="status" class="{{ $inputClass }}">
                        <option value="draft" @selected(old('status', $post->status) === 'draft')>Draft</option>
                        <option value="published" @selected(old('status', $post->status) === 'published')>Published</option>
                    </select>
                </div>

                <div>
                    <label for="locale" class="{{ $labelClass }}">Language</label>
                    <select id="locale" name="locale" class="{{ $inputClass }}">
                        @foreach (\App\Models\Post::LOCALES as $loc)
                            <option value="{{ $loc }}" @selected(old('locale', $post->locale ?: 'en') === $loc)>{{ strtoupper($loc) }}</option>
                        @endforeach
                    </select>
                    <p class="text-xs text-gray-400 mt-1">The public site is English-only for now — only EN articles appear.</p>
                </div>

                <div class="flex flex-col gap-2 pt-2">
                    <button type="submit" class="w-full px-4 py-2.5 rounded-md bg-blue-600 text-white text-sm font-medium hover:bg-blue-700 transition">
                        {{ $isEdit ? 'Save changes' : 'Create article' }}
                    </button>
                    @if ($isEdit && $post->isPublished() && $post->locale === 'en')
                        <a href="{{ route('blog.show', $post->slug) }}" target="_blank" rel="noopener"
                           class="w-full text-center px-4 py-2.5 rounded-md border border-gray-300 text-sm font-medium text-gray-700 hover:bg-gray-50 transition">
                            View live ↗
                        </a>
                    @endif
                    <a href="{{ route('admin.posts.index') }}" class="w-full text-center px-4 py-2 text-sm text-gray-500 hover:text-gray-700">Cancel</a>
                </div>
            </div>

            <div class="bg-white rounded-lg border border-gray-200 shadow-sm p-6 space-y-3">
                <label class="{{ $labelClass }}">Cover image</label>
                @if ($post->coverUrl())
                    <img src="{{ $post->coverUrl() }}" alt="" class="w-full rounded-md border border-gray-200">
                @else
                    <div class="w-full aspect-[1.75] rounded-md border border-dashed border-gray-300 bg-gray-50 flex items-center justify-center text-gray-400">
                        <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                    </div>
                @endif
                <input type="file" name="cover" accept="image/*" class="w-full text-sm text-gray-600 file:mr-3 file:py-2 file:px-3 file:rounded-md file:border-0 file:bg-gray-100 file:text-sm file:font-medium file:cursor-pointer hover:file:bg-gray-200">
                <p class="text-xs text-gray-400">Shown on blog cards and at the top of the article. JPG/PNG, up to 4 MB.</p>
            </div>
        </div>
    </div>
</form>

@if ($isEdit)
    <form method="POST" action="{{ route('admin.posts.destroy', $post) }}" class="mt-6"
          onsubmit="return confirm('Delete this article permanently?');">
        @csrf
        @method('DELETE')
        <button type="submit" class="text-sm text-red-600 hover:underline">Delete article</button>
    </form>
@endif

<script src="https://cdn.jsdelivr.net/npm/tinymce@7.6.0/tinymce.min.js" referrerpolicy="origin"></script>
<script>
    tinymce.init({
        selector: '#body',
        license_key: 'gpl',
        height: 500,
        menubar: false,
        plugins: 'advlist autolink lists link image media table code wordcount',
        toolbar: 'undo redo | blocks | bold italic | bullist numlist | link image media table | code',
        branding: false,
        promotion: false,
    });
</script>
