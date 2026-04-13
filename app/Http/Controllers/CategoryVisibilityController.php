<?php

namespace App\Http\Controllers;

use App\Http\Requests\CategoryVisibilityRequest;
use App\Http\Resources\VisibilityQuestionResource;
use App\Models\AppUserCategorySetting;
use App\Models\Category;
use App\Services\VisibilityQuestionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CategoryVisibilityController extends Controller
{
    public function __construct(
        private VisibilityQuestionService $visibilityQuestionService
    ) {}

    public function funnelQuestions(): JsonResponse
    {
        $questions = $this->visibilityQuestionService->getFunnelQuestions();

        return response()->json([
            'data' => VisibilityQuestionResource::collection($questions)->resolve(),
        ]);
    }

    public function visibilityTags(Request $request): JsonResponse
    {
        $tagsConfig = $this->visibilityQuestionService->getVisibilityTags();
        $user = $request->user();
        $disabledTags = AppUserCategorySetting::where('app_user_id', $user->id)
            ->whereNotNull('visibility_tag')
            ->distinct()
            ->pluck('visibility_tag')
            ->flip()
            ->all();

        $data = array_map(fn (array $row) => [
            'visibility_tag' => $row['visibility_tag'],
            'enabled' => ! isset($disabledTags[$row['visibility_tag']]),
            'translation_key' => $row['translation_key'],
        ], $tagsConfig);

        return response()->json(['data' => $data]);
    }

    public function update(CategoryVisibilityRequest $request): JsonResponse
    {
        $user = $request->user();
        $tag = $request->validated('visibility_tag');
        $enabled = $request->validated('enabled');

        if ($enabled) {
            AppUserCategorySetting::where('app_user_id', $user->id)
                ->where('visibility_tag', $tag)
                ->delete();
        } else {
            $categories = Category::where('visibility_tag', $tag)->get();
            if ($categories->isEmpty()) {
                return response()->json([
                    'message' => "No categories found for visibility_tag '{$tag}'. Run: php artisan categories:seed",
                ], 422);
            }
            foreach ($categories as $category) {
                AppUserCategorySetting::firstOrCreate(
                    ['app_user_id' => $user->id, 'category_id' => $category->id],
                    ['visibility_tag' => $tag]
                );
            }
        }

        return response()->json(['message' => 'OK']);
    }
}
