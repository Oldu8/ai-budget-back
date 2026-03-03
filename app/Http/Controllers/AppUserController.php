<?php

namespace App\Http\Controllers;

use App\Models\AppUserSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AppUserController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->load('settings');

        return response()->json($user);
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

    public function updateSettings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'currency' => ['required', 'string', 'max:10'],
            'language' => ['required', 'string', 'max:10'],
            'timezone' => ['required', 'string', 'max:64', 'timezone:all'],
        ]);

        $settings = AppUserSettings::updateOrCreate(
            ['app_user_id' => $request->user()->id],
            $validated,
        );

        return response()->json($settings);
    }
}
