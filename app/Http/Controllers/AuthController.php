<?php

namespace App\Http\Controllers;

use App\Models\AppUser;
use App\Services\DiscordNotifier;
use App\Services\EnsureDefaultSubscriptionService;
use App\Services\ErrorNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function __construct(
        private EnsureDefaultSubscriptionService $ensureDefaultSubscription,
    ) {}

    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:6'],
        ]);

        $response = $this->supabasePost('/auth/v1/signup', [
            'email' => $validated['email'],
            'password' => $validated['password'],
        ]);

        if ($response->failed()) {
            $message = $this->supabaseErrorMessage($response, 'Registration failed');
            $status = $response->status();
            $context = ['email' => $validated['email'], 'status' => $status, 'response' => $response->json()];
            if (ErrorNotifier::isCritical($status)) {
                ErrorNotifier::notify('Register: Supabase rejected', $message, $context);
            }

            return response()->json(['message' => $message], $status);
        }

        $data = $response->json();

        if (! isset($data['user'])) {
            $msg = $this->supabaseErrorMessageFromArray($data, 'Invalid Supabase response');
            Log::error('Register: Supabase response missing user', ['response' => $data]);
            ErrorNotifier::notify('Register: invalid Supabase response', $msg, ['response' => $data]);

            return response()->json(['message' => $msg], 500);
        }

        try {
            $appUser = AppUser::firstOrCreate(
                ['supabase_user_id' => $data['user']['id']],
                ['email' => $data['user']['email']],
            );

            $emailConfirmedAt = $data['user']['email_confirmed_at'] ?? null;
            if ($emailConfirmedAt && ! $appUser->email_verified_at) {
                $appUser->email_verified_at = $emailConfirmedAt;
                $appUser->save();
            }

            $this->ensureDefaultSubscription->ensureFreeSubscription($appUser);

            $this->notifyDiscordRegistration($appUser);

            return response()->json([
                'access_token' => $data['access_token'],
                'refresh_token' => $data['refresh_token'],
                'user' => $appUser,
            ], 201);
        } catch (\Throwable $e) {
            $context = [
                'email' => ($data['user'] ?? [])['email'] ?? $validated['email'] ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ];
            Log::error('Register failed after Supabase signup', $context);
            file_put_contents('php://stderr', "[Register Error] {$e->getMessage()}\n{$e->getTraceAsString()}\n");
            ErrorNotifier::notify('Register failed', $e->getMessage(), $context);

            return response()->json(['message' => 'Registration failed'], 500);
        }
    }

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $response = $this->supabasePost('/auth/v1/token?grant_type=password', [
            'email' => $validated['email'],
            'password' => $validated['password'],
        ]);

        if ($response->failed()) {
            $message = $this->supabaseErrorMessage($response, 'Invalid credentials');
            return response()->json(['message' => $message], $response->status());
        }

        $data = $response->json();

        // Require an app row created via /api/auth/register — do not provision on login
        // (avoids "silent" accounts when only Supabase auth exists).
        $appUser = AppUser::query()
            ->where('supabase_user_id', $data['user']['id'])
            ->first();

        if (! $appUser) {
            return response()->json([
                'message' => 'This email is not registered. Please sign up before signing in.',
            ], 401);
        }

        $supabaseEmail = $data['user']['email'] ?? null;
        if ($supabaseEmail && $appUser->email !== $supabaseEmail) {
            $appUser->email = $supabaseEmail;
            $appUser->save();
        }

        $emailConfirmedAt = $data['user']['email_confirmed_at'] ?? null;
        if ($emailConfirmedAt && ! $appUser->email_verified_at) {
            $appUser->email_verified_at = $emailConfirmedAt;
            $appUser->save();
        }

        $this->ensureDefaultSubscription->ensureFreeSubscription($appUser);

        $appUser->load('settings');

        return response()->json([
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'],
            'user' => $appUser,
        ]);
    }

    public function refresh(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'refresh_token' => ['required', 'string'],
        ]);

        $response = $this->supabasePost('/auth/v1/token?grant_type=refresh_token', [
            'refresh_token' => $validated['refresh_token'],
        ]);

        if ($response->failed()) {
            return response()->json([
                'message' => 'Token refresh failed',
            ], 401);
        }

        $data = $response->json();

        return response()->json([
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = substr($request->header('Authorization', ''), 7);

        if (! $token) {
            return response()->json(['message' => 'No token provided'], 400);
        }

        $url = rtrim(config('services.supabase.url'), '/');

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'apiKey' => config('services.supabase.service_role'),
        ])->post($url . '/auth/v1/logout');

        if ($response->failed()) {
            return response()->json(['message' => 'Logout failed'], $response->status());
        }

        return response()->json(['message' => 'Logged out']);
    }

    /** Discord #new-users — minimal PII unless DISCORD_REGISTRATION_INCLUDE_EMAIL=true */
    private function notifyDiscordRegistration(AppUser $appUser): void
    {
        $lines = ['`app_user_id`: ' . $appUser->id];
        if (config('services.discord.registration_include_email')) {
            if ($appUser->email) {
                $lines[] = $appUser->email;
            }
        } elseif ($appUser->email && str_contains($appUser->email, '@')) {
            $lines[] = '`domain`: `@' . Str::after($appUser->email, '@') . '`';
        }

        DiscordNotifier::registration('New user registered', implode("\n", $lines));
    }

    private function supabasePost(string $path, array $body): \Illuminate\Http\Client\Response
    {
        $url = rtrim(config('services.supabase.url'), '/');

        return Http::withHeaders([
            'apiKey' => config('services.supabase.anon_key'),
            'Content-Type' => 'application/json',
        ])->post($url . $path, $body);
    }

    /** Supabase returns msg (old API) or message (new API). Maps common codes to user-friendly text. */
    private function supabaseErrorMessage(\Illuminate\Http\Client\Response $response, string $fallback): string
    {
        $data = $response->json();

        return $this->supabaseErrorMessageFromArray($data, $fallback);
    }

    private function supabaseErrorMessageFromArray(?array $data, string $fallback): string
    {
        if (! $data) {
            return $fallback;
        }
        $text = $data['message'] ?? $data['msg'] ?? $data['error_description'] ?? null;
        $code = (string) ($data['code'] ?? $data['error'] ?? '');
        $textLower = strtolower($text ?? '');

        $userFriendly = match (true) {
            str_contains($code, 'user_already') || str_contains($textLower, 'already registered') => 'This email is already registered. Try logging in.',
            str_contains($code, 'rate_limit') || str_contains($textLower, 'rate limit') => 'Too many registration attempts. Please try again in an hour.',
            str_contains($code, 'weak_password') || str_contains($textLower, 'weak password') => 'Password is too weak. Use at least 6 characters.',
            str_contains($code, 'invalid_credentials') || str_contains($textLower, 'invalid') => 'Invalid email or password.',
            default => $text ?? $fallback,
        };

        return $userFriendly ?: $fallback;
    }
}
