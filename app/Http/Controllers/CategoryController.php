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

        return response()->json($categories);
    }
}
