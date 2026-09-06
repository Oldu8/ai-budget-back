<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PostRequest;
use App\Models\Post;
use App\Services\SupabaseStorageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Illuminate\View\View;

class PostController extends Controller
{
    public function index(): View
    {
        $posts = Post::query()
            ->orderByDesc('created_at')
            ->paginate(20);

        return view('admin.posts.index', ['posts' => $posts]);
    }

    public function create(): View
    {
        return view('admin.posts.create', ['post' => new Post(['status' => Post::STATUS_DRAFT])]);
    }

    public function store(PostRequest $request): RedirectResponse
    {
        $post = new Post;
        $this->fill($post, $request);
        $post->translation_group_id = $request->input('translation_group_id') ?: (string) Str::uuid();
        $post->save();

        return redirect()
            ->route('admin.posts.edit', $post)
            ->with('status', 'Article created.');
    }

    public function edit(Post $post): View
    {
        return view('admin.posts.edit', ['post' => $post]);
    }

    public function update(PostRequest $request, Post $post): RedirectResponse
    {
        $this->fill($post, $request);
        if ($request->filled('translation_group_id')) {
            $post->translation_group_id = $request->input('translation_group_id');
        }
        $post->save();

        return redirect()
            ->route('admin.posts.edit', $post)
            ->with('status', 'Article saved.');
    }

    public function destroy(Post $post): RedirectResponse
    {
        $post->delete();

        return redirect()
            ->route('admin.posts.index')
            ->with('status', 'Article deleted.');
    }

    /** Copy validated fields onto the model, handling slug, cover upload and publish timestamp. */
    private function fill(Post $post, PostRequest $request): void
    {
        $post->fill($request->safe()->except(['slug', 'cover', 'translation_group_id']));

        $slug = $request->input('slug') ?: Str::slug($request->input('title'));
        $post->slug = $slug;

        if ($request->hasFile('cover')) {
            $storage = new SupabaseStorageService(config('services.supabase.blog_storage_bucket'));
            $path = $storage->upload($request->file('cover'));
            $post->cover_image = $storage->publicUrl($path);
        }

        if ($post->status === Post::STATUS_PUBLISHED && $post->published_at === null) {
            $post->published_at = now();
        }
    }
}
