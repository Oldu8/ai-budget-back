<?php

namespace App\Http\Controllers;

use App\Models\AppUser;
use App\Services\Auth\RefreshTokenStore;
use App\Services\Auth\RegistrationNotifier;
use App\Services\Auth\Social\InvalidSocialTokenException;
use App\Services\Auth\Social\SocialVerifierFactory;
use App\Services\Auth\SocialAuthService;
use App\Services\Auth\SocialEmailAlreadyRegisteredException;
use App\Services\Auth\TokenResponseBuilder;
use App\Services\EnsureDefaultSubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class AuthController extends Controller
{
    public function __construct(
        private readonly EnsureDefaultSubscriptionService $ensureDefaultSubscription,
        private readonly TokenResponseBuilder $tokens,
        private readonly RefreshTokenStore $refreshTokens,
        private readonly RegistrationNotifier $registrationNotifier,
    ) {}

    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255', Rule::unique('app_users', 'email')],
            'password' => ['required', 'string', 'min:6'],
        ], [
            'email.unique' => 'This email is already registered. Try logging in.',
        ]);

        $user = AppUser::create([
            'email' => $validated['email'],
            'password' => $validated['password'], // hashed via model cast
        ]);

        $this->ensureDefaultSubscription->ensureFreeSubscription($user);
        $this->registrationNotifier->notify($user);

        return response()->json($this->tokens->forUser($user), 201);
    }

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = AppUser::where('email', $validated['email'])->first();

        // Hash::check is null-safe: social-only users (password null) simply fail here.
        if (! $user || ! Hash::check($validated['password'], (string) $user->password)) {
            return response()->json(['message' => 'Invalid email or password.'], 401);
        }

        $this->ensureDefaultSubscription->ensureFreeSubscription($user);

        return response()->json($this->tokens->forUser($user));
    }

    public function social(Request $request, SocialAuthService $socialAuth): JsonResponse
    {
        $validated = $request->validate([
            'provider' => ['required', 'string', Rule::in(SocialVerifierFactory::PROVIDERS)],
            'id_token' => ['required', 'string'],
            'name' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $payload = $socialAuth->authenticate(
                $validated['provider'],
                $validated['id_token'],
                $validated['name'] ?? null,
            );
        } catch (InvalidSocialTokenException) {
            return response()->json(['message' => 'Could not verify your sign-in. Please try again.'], 401);
        } catch (SocialEmailAlreadyRegisteredException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json($payload);
    }

    public function refresh(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'refresh_token' => ['required', 'string'],
        ]);

        $rotated = $this->refreshTokens->rotate($validated['refresh_token']);

        if (! $rotated) {
            return response()->json(['message' => 'Token refresh failed'], 401);
        }

        return response()->json($this->tokens->withRefreshToken($rotated['user'], $rotated['token']));
    }

    public function logout(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'refresh_token' => ['required', 'string'],
        ]);

        $this->refreshTokens->revoke($validated['refresh_token']);

        return response()->json(['message' => 'Logged out']);
    }
}
