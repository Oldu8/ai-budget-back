<?php

namespace App\Http\Controllers;

use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    /**
     * List categories tree. Filtered by user's disabled list, or by ?visibility_tag for funnel preview.
     */
    public function index(Request $request): JsonResponse
    {
        if ($request->has('visibility_tag')) {
            // Funnel preview: return only parents/children that have this tag (no user filter).
            $tag = $request->query('visibility_tag');
            $categories = Category::with(['children' => fn ($q) => $q->where('visibility_tag', $tag)->orderBy('order')])
                ->whereNull('category_id')
                ->where(function ($q) use ($tag) {
                    $q->where('visibility_tag', $tag)
                        ->orWhereHas('children', fn ($q) => $q->where('visibility_tag', $tag));
                })
                ->orderBy('order')
                ->get();
        } else {
            // Normal list: exclude categories that the user has disabled (app_user_category_settings).
            $user = $request->user();
            $disabledIds = $user->disabledCategorySettings()->pluck('category_id')->all();
            $categories = Category::with(['children' => fn ($q) => $q->when(! empty($disabledIds), fn ($q) => $q->whereNotIn('id', $disabledIds))->orderBy('order')])
                ->whereNull('category_id')
                ->when(! empty($disabledIds), fn ($q) => $q->whereNotIn('id', $disabledIds))
                ->orderBy('order')
                ->get();
        }

        $data = $categories->map(fn (Category $c) => [
            'id' => $c->id,
            'name' => $c->name,
            'label' => $c->label,
            'children' => $c->children->map(fn (Category $child) => [
                'id' => $child->id,
                'name' => $child->name,
                'label' => $child->label,
            ])->values()->all(),
        ])->values()->all();

        return response()->json(['data' => $data]);
    }
}
