<?php

namespace App\Http\Controllers;

use App\Http\Requests\CategoryVisibilityRequest;
use App\Models\AppUserCategorySetting;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CategoryVisibilityController extends Controller
{
    public function funnelQuestions(): JsonResponse
    {
        $tags = require database_path('data/visibility_tags.php');

        $data = array_map(fn (array $row) => [
            'visibility_tag' => $row['tag'],
            'question_key' => $row['funnel_question_key'],
            'label' => $row['funnel_question_label'],
            'type' => 'boolean',
        ], $tags);

        return response()->json(['data' => $data]);
    }

    public function visibilityTags(Request $request): JsonResponse
    {
        $tagsConfig = require database_path('data/visibility_tags.php');
        $user = $request->user();
        $disabledTags = AppUserCategorySetting::where('app_user_id', $user->id)
            ->whereNotNull('visibility_tag')
            ->distinct()
            ->pluck('visibility_tag')
            ->flip()
            ->all();

        $data = array_map(fn (array $row) => [
            'tag' => $row['tag'],
            'label' => $row['label'],
            'enabled' => !isset($disabledTags[$row['tag']]),
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
            $rows = $categories->map(fn (Category $c) => [
                'app_user_id' => $user->id,
                'category_id' => $c->id,
                'visibility_tag' => $tag,
            ])->all();
            foreach ($rows as $row) {
                AppUserCategorySetting::firstOrCreate(
                    ['app_user_id' => $row['app_user_id'], 'category_id' => $row['category_id']],
                    ['visibility_tag' => $row['visibility_tag']]
                );
            }
        }

        return response()->json(['message' => 'OK']);
    }
}
