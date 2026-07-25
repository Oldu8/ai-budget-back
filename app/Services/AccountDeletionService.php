<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AppUser;
use App\Models\UserSubscription;
use App\Services\Auth\RefreshTokenStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;
use Stripe\Stripe;
use Stripe\Subscription;

/**
 * Soft-deletes an AppUser for App Store account-deletion compliance.
 *
 * // [SOLID:S] Account deletion orchestration lives here — HTTP stays in the controller
 */
final class AccountDeletionService
{
    public function __construct(
        private readonly RefreshTokenStore $refreshTokens,
    ) {
        Stripe::setApiKey(config('services.stripe.secret'));
    }

    public function delete(AppUser $user): void
    {
        $this->cancelStripeSubscriptions($user);

        DB::transaction(function () use ($user) {
            $this->refreshTokens->revokeAllForUser($user);
            $user->identities()->delete();

            $user->forceFill([
                'email' => 'deleted+'.$user->id.'@deleted.local',
                'first_name' => null,
                'last_name' => null,
                'password' => null,
                'email_verified_at' => null,
                'payment_customer_id' => null,
            ])->save();

            $user->delete();
        });
    }

    private function cancelStripeSubscriptions(AppUser $user): void
    {
        $subscriptionIds = UserSubscription::query()
            ->where('app_user_id', $user->id)
            ->whereNotNull('payment_subscription_id')
            ->pluck('payment_subscription_id');

        foreach ($subscriptionIds as $subscriptionId) {
            try {
                Subscription::retrieve((string) $subscriptionId)->cancel();
            } catch (ApiErrorException $e) {
                Log::warning('AccountDeletion: Stripe cancel failed', [
                    'app_user_id' => $user->id,
                    'payment_subscription_id' => $subscriptionId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
