<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\AnalyticsDashboardRequest;
use App\Models\AppUser;
use App\Services\AnalyticsDashboardService;
use Illuminate\Http\JsonResponse;

/**
 * Plus/Premium analytics for a calendar month. Access: feature.access:analytics_dashboard.
 */
final class AnalyticsDashboardController extends Controller
{
    public function __construct(
        private AnalyticsDashboardService $analyticsDashboard,
    ) {}

    public function __invoke(AnalyticsDashboardRequest $request): JsonResponse
    {
        /** @var AppUser $user */
        $user = $request->user();
        $user->load('settings');
        $month = $request->validated('month');

        return response()->json($this->analyticsDashboard->build($user, $month));
    }
}
