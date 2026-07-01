<?php

namespace App\Http\Controllers;

use App\Http\Requests\CategoryVisibilityRequest;
use App\Http\Resources\VisibilityQuestionResource;
use App\Models\AppUserCategorySetting;
use App\Models\Category;
use App\Services\VisibilityQuestionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

    /**
     * Batch update: accepts a list of { visibility_tag, enabled } preferences
     * and applies them all in a single request.
     */
    public function update(CategoryVisibilityRequest $request): JsonResponse
    {
        $user = $request->user();

        // De-duplicate by tag (last one wins) so conflicting entries are safe.
        $preferences = collect($request->validated('preferences'))
            ->keyBy('visibility_tag')
            ->values();

        $disabledTags = $preferences->where('enabled', false)->pluck('visibility_tag');

        // Categories that back the tags the user wants to hide, grouped by tag.
        $categoriesByTag = Category::whereIn('visibility_tag', $disabledTags)
            ->get()
            ->groupBy('visibility_tag');

        $missingTags = $disabledTags
            ->reject(fn (string $tag) => $categoriesByTag->has($tag))
            ->unique()
            ->values();

        if ($missingTags->isNotEmpty()) {
            return response()->json([
                'message' => "No categories found for visibility_tag(s): {$missingTags->implode(', ')}. Run: php artisan categories:seed",
            ], 422);
        }

        DB::transaction(function () use ($user, $preferences, $categoriesByTag) {
            foreach ($preferences as $pref) {
                $tag = $pref['visibility_tag'];

                if ($pref['enabled']) {
                    AppUserCategorySetting::where('app_user_id', $user->id)
                        ->where('visibility_tag', $tag)
                        ->delete();

                    continue;
                }

                foreach ($categoriesByTag->get($tag) as $category) {
                    AppUserCategorySetting::firstOrCreate(
                        ['app_user_id' => $user->id, 'category_id' => $category->id],
                        ['visibility_tag' => $tag]
                    );
                }
            }
        });

        return response()->json(['message' => 'OK']);
    }
}
