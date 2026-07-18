<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\AppUser;
use App\Models\AppUserIdentity;
use App\Services\Auth\Social\SocialIdentity;
use App\Services\Auth\Social\SocialVerifierFactory;
use App\Services\EnsureDefaultSubscriptionService;
use Illuminate\Support\Facades\DB;

/**
 * Authenticates a user from a verified social identity:
 * find by identity -> find by (verified) email -> create, always linking the identity.
 * Depends on the verifier abstraction, not concrete providers.
 */
final class SocialAuthService
{
    public function __construct(
        private readonly SocialVerifierFactory $verifiers,
        private readonly EnsureDefaultSubscriptionService $ensureDefaultSubscription,
        private readonly TokenResponseBuilder $tokens,
        private readonly RegistrationNotifier $registrationNotifier,
    ) {}

    /**
     * @param  string|null  $name  Full name supplied by the client (Apple sends it only on first sign-in).
     * @return array{access_token: string, refresh_token: string, expires_in: int, user: AppUser}
     */
    public function authenticate(string $provider, string $idToken, ?string $name = null): array
    {
        $identity = $this->verifiers->for($provider)->verify($idToken);

        [$user, $isNew] = DB::transaction(
            fn () => $this->resolveUser($identity, $name),
        );

        if ($user->activeSubscription() === null) {
            $this->ensureDefaultSubscription->ensureFreeSubscription($user);
        }

        if ($isNew) {
            $this->registrationNotifier->notify($user);
        }

        return $this->tokens->forUser($user);
    }

    /**
     * @return array{0: AppUser, 1: bool} resolved user and whether it was just created
     */
    private function resolveUser(SocialIdentity $identity, ?string $clientName): array
    {
        // 1. Already linked identity -> that user.
        $existingIdentity = AppUserIdentity::query()
            ->where('provider', $identity->provider)
            ->where('provider_user_id', $identity->providerUserId)
            ->first();

        if ($existingIdentity) {
            $user = AppUser::findOrFail($existingIdentity->app_user_id);
            $this->fillMissingName($user, $identity->name ?? $clientName);

            return [$user, false];
        }

        // 2. Link to an existing account only when the provider asserts a verified email
        //    (otherwise an unverified email could be used to hijack an account).
        if ($identity->email && $identity->emailVerified) {
            $byEmail = AppUser::where('email', $identity->email)->first();
            if ($byEmail) {
                $this->linkIdentity($byEmail, $identity);
                $this->fillMissingName($byEmail, $identity->name ?? $clientName);

                return [$byEmail, false];
            }
        }

        // 3. New user — unless this email already belongs to someone else. That only
        //    happens here because step 2 requires a *verified* email to link; an
        //    unverified match must not silently create a second account with the
        //    same email (the column is unique, so it would just crash otherwise).
        if ($identity->email && AppUser::where('email', $identity->email)->exists()) {
            throw new SocialEmailAlreadyRegisteredException(
                'An account with this email already exists. Please sign in using the method you originally used to create it.',
            );
        }

        [$first, $last] = $this->splitName($identity->name ?? $clientName);
        $user = AppUser::create([
            'email' => $identity->email,
            'first_name' => $first,
            'last_name' => $last,
            'email_verified_at' => $identity->emailVerified ? now() : null,
        ]);
        $this->linkIdentity($user, $identity);

        return [$user, true];
    }

    private function linkIdentity(AppUser $user, SocialIdentity $identity): void
    {
        AppUserIdentity::firstOrCreate([
            'provider' => $identity->provider,
            'provider_user_id' => $identity->providerUserId,
        ], [
            'app_user_id' => $user->id,
        ]);
    }

    private function fillMissingName(AppUser $user, ?string $name): void
    {
        if (! $name || ($user->first_name && $user->last_name)) {
            return;
        }

        [$first, $last] = $this->splitName($name);
        $user->forceFill(array_filter([
            'first_name' => $user->first_name ?: $first,
            'last_name' => $user->last_name ?: $last,
        ]))->save();
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private function splitName(?string $name): array
    {
        $name = trim((string) $name);
        if ($name === '') {
            return [null, null];
        }

        $parts = preg_split('/\s+/', $name) ?: [];
        $first = array_shift($parts);
        $last = $parts ? implode(' ', $parts) : null;

        return [$first, $last];
    }
}
