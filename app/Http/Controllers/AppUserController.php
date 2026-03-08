<?php

namespace App\Http\Controllers;

use App\Http\Requests\AppUserSettingsRequest;
use App\Models\AppUserSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AppUserController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->load('settings');

        $subscription = $user->activeSubscription()?->load('plan');
        $scanUsage = [
            'scans_remaining' => 0,
            'scans_limit' => 0,
            'period_end' => null,
        ];
        if ($subscription && $subscription->relationLoaded('plan')) {
            $limit = $subscription->plan->check_limit;
            $used = $subscription->used_checks_count;
            $scanUsage = [
                'scans_remaining' => max(0, $limit - $used),
                'scans_limit' => $limit,
                'period_end' => $subscription->current_period_end?->toIso8601String(),
            ];
        }

        return response()->json(array_merge($user->toArray(), [
            'subscription' => $subscription?->toArray(),
            'scan_usage' => $scanUsage,
        ]));
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'first_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'last_name' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $user = $request->user();
        $user->update($validated);

        return response()->json($user);
    }

    public function destroy(Request $request): JsonResponse
    {
        $request->user()->delete();

        return response()->json(['message' => 'Account deleted'], 200);
    }

    public function showSettings(Request $request): JsonResponse
    {
        $settings = $request->user()->settings;

        if (!$settings) {
            return response()->json(['message' => 'Settings not configured'], 404);
        }

        return response()->json($settings);
    }

    public function createSettings(AppUserSettingsRequest $request): JsonResponse
    {
        $user = $request->user();

        if ($user->settings) {
            return response()->json(['message' => 'Settings already configured'], 409);
        }

        $settings = AppUserSettings::create([
            'app_user_id' => $user->id,
            ...$request->validated(),
        ]);

        return response()->json($settings, 201);
    }

    public function updateSettings(AppUserSettingsRequest $request): JsonResponse
    {
        $settings = $request->user()->settings;

        if (!$settings) {
            return response()->json(['message' => 'Settings not configured'], 404);
        }

        $settings->update($request->validated());

        return response()->json($settings);
    }
}
