<?php

namespace App\Http\Controllers;

use App\Http\Requests\AppUserSettingsRequest;
use App\Http\Requests\ChangePasswordRequest;
use App\Http\Requests\UpdateMeRequest;
use App\Models\AppUser;
use App\Models\AppUserSettings;
use App\Models\UserSubscription;
use App\Services\Auth\RefreshTokenStore;
use App\Services\ScanQuotaResetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AppUserController extends Controller
{
    public function __construct(
        private ScanQuotaResetService $scanQuotaReset,
    ) {}

    /** @return array<string, mixed> */
    private function userPayload(AppUser $user): array
    {
        return [
            'id' => $user->id,
            'email' => $user->email,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email_verified_at' => $user->email_verified_at?->toIso8601String(),
            // Lets the client hide "change password" for social-only accounts.
            'has_password' => $user->password !== null,
        ];
    }

    /** @return array<string, mixed> */
    private function settingsPayload(AppUserSettings $settings): array
    {
        return $settings->only(['currency', 'language', 'timezone']);
    }

    /** @return array<string, mixed>|null */
    private function subscriptionPayload(?UserSubscription $subscription): ?array
    {
        if (! $subscription || ! $subscription->relationLoaded('plan') || ! $subscription->relationLoaded('status')) {
            return null;
        }

        return [
            'id' => $subscription->id,
            'status' => $subscription->status->name,
            'used_checks_count' => $subscription->used_checks_count,
            'current_period_end' => $subscription->current_period_end?->toIso8601String(),
            'plan' => [
                'type' => $subscription->plan->type->value,
                'price' => $subscription->plan->price,
                'check_limit' => $subscription->plan->check_limit,
                'label' => $subscription->plan->label,
                'description' => $subscription->plan->description ?? '',
            ],
        ];
    }

    public function show(Request $request): JsonResponse
    {
        /** @var AppUser $user */
        $user = $request->user();
        $user->load('settings');

        $subscription = $user->activeSubscription()?->load(['plan', 'status']);
        $scanUsage = [
            'scans_remaining' => 0,
            'scans_limit' => 0,
            'period_end' => null,
            'scans_reset_at' => null,
        ];
        if ($subscription && $subscription->relationLoaded('plan')) {
            $this->scanQuotaReset->ensureReset($subscription);

            $limit = $subscription->plan->check_limit;
            $used = $subscription->used_checks_count;
            $scanUsage = [
                'scans_remaining' => max(0, $limit - $used),
                'scans_limit' => $limit,
                'period_end' => $subscription->current_period_end?->toIso8601String(),
                'scans_reset_at' => $subscription->scans_reset_at?->toIso8601String(),
            ];
        }

        $subscriptionPayload = $this->subscriptionPayload($subscription);

        return response()->json([
            ...$this->userPayload($user),
            'settings' => $user->settings ? $this->settingsPayload($user->settings) : null,
            'subscription' => $subscriptionPayload,
            'subscription_status' => $subscription?->status?->name,
            'scan_usage' => $scanUsage,
        ]);
    }

    public function update(UpdateMeRequest $request): JsonResponse
    {
        /** @var AppUser $user */
        $user = $request->user();
        $user->update($request->validated());

        return response()->json($this->userPayload($user->refresh()));
    }

    public function destroy(Request $request): JsonResponse
    {
        $request->user()->delete();

        return response()->json(['message' => 'Account deleted'], 200);
    }

    /**
     * Change password for a logged-in user who already has one.
     * Social-only accounts (no password) are rejected — setting a first password is a
     * separate, deferred flow. No email is sent here.
     */
    public function changePassword(ChangePasswordRequest $request, RefreshTokenStore $refreshTokens): JsonResponse
    {
        /** @var AppUser $user */
        $user = $request->user();
        $validated = $request->validated();

        if ($user->password === null) {
            return response()->json(['message' => 'No password is set for this account.'], 409);
        }

        if (! Hash::check($validated['current_password'], $user->password)) {
            return response()->json(['message' => 'Current password is incorrect.'], 422);
        }

        $user->password = $validated['new_password']; // hashed via model cast
        $user->save();

        // Log out other sessions; keep this device signed in via its presented refresh token.
        $refreshTokens->revokeOthersForUser($user, $validated['refresh_token'] ?? null);

        return response()->json(['message' => 'Password updated']);
    }

    public function showSettings(Request $request): JsonResponse
    {
        $settings = $request->user()->settings;

        if (! $settings) {
            return response()->json(['message' => 'Settings not configured'], 404);
        }

        return response()->json($this->settingsPayload($settings));
    }

    public function createSettings(AppUserSettingsRequest $request): JsonResponse
    {
        /** @var AppUser $user */
        $user = $request->user();

        if ($user->settings) {
            return response()->json(['message' => 'Settings already configured'], 409);
        }

        $settings = AppUserSettings::create([
            'app_user_id' => $user->id,
            ...$request->validated(),
        ]);

        return response()->json($this->settingsPayload($settings), 201);
    }

    public function updateSettings(AppUserSettingsRequest $request): JsonResponse
    {
        $settings = $request->user()->settings;

        if (! $settings) {
            return response()->json(['message' => 'Settings not configured'], 404);
        }

        $data = $request->validated();

        // The accounting currency is locked once set: it is the conversion target
        // for every stored amount, so changing it would make historical
        // conversions inconsistent. Only the initial createSettings may set it.
        if ($settings->currency) {
            unset($data['currency']);
        }

        // Never overwrite with null or empty - only update fields with valid values
        $safeData = array_filter($data, fn ($value) => $value !== null && $value !== '');

        $settings->update($safeData);

        return response()->json($this->settingsPayload($settings));
    }
}
