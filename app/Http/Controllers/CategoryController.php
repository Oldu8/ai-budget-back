<?php

namespace App\Http\Controllers;

use App\Models\Category;
use Illuminate\Http\JsonResponse;

class CategoryController extends Controller
{
    public function index(): JsonResponse
    {
        $categories = Category::with('children')
            ->whereNull('category_id')
            ->orderBy('label')
            ->get();

        $data = $categories->map(fn (Category $c) => [
            'id' => $c->id,
            'slug' => $c->name,
            'label' => $c->label,
            'children' => $c->children->map(fn (Category $child) => [
                'id' => $child->id,
                'slug' => $child->name,
                'label' => $child->label,
            ])->values()->all(),
        ])->values()->all();

        return response()->json(['data' => $data]);
    }
}
