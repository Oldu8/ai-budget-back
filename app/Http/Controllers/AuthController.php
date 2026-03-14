<?php

namespace App\Http\Controllers;

use App\Models\AppUser;
use App\Services\ErrorNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AuthController extends Controller
{
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
            return response()->json([
                'message' => $response->json('msg', 'Registration failed'),
            ], $response->status());
        }

        $data = $response->json();

        try {
            $appUser = AppUser::firstOrCreate(
                ['supabase_user_id' => $data['user']['id']],
                ['email' => $data['user']['email']],
            );

            $emailConfirmedAt = $data['user']['email_confirmed_at'] ?? null;
            if ($emailConfirmedAt && !$appUser->email_verified_at) {
                $appUser->email_verified_at = $emailConfirmedAt;
                $appUser->save();
            }

            return response()->json([
                'access_token' => $data['access_token'],
                'refresh_token' => $data['refresh_token'],
                'user' => $appUser,
            ], 201);
        } catch (\Throwable $e) {
            $context = [
                'email' => $data['user']['email'] ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ];
            Log::error('Register failed after Supabase signup', $context);
            file_put_contents('php://stderr', "[Register Error] {$e->getMessage()}\n{$e->getTraceAsString()}\n");
            ErrorNotifier::notify('Register failed', $e->getMessage(), $context);

            return response()->json([
                'message' => 'Registration failed',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
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
            return response()->json([
                'message' => $response->json('error_description', 'Invalid credentials'),
            ], 401);
        }

        $data = $response->json();

        $appUser = AppUser::firstOrCreate(
            ['supabase_user_id' => $data['user']['id']],
            ['email' => $data['user']['email']],
        );

        $emailConfirmedAt = $data['user']['email_confirmed_at'] ?? null;
        if ($emailConfirmedAt && !$appUser->email_verified_at) {
            $appUser->email_verified_at = $emailConfirmedAt;
            $appUser->save();
        }

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

        if (!$token) {
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

    private function supabasePost(string $path, array $body): \Illuminate\Http\Client\Response
    {
        $url = rtrim(config('services.supabase.url'), '/');

        return Http::withHeaders([
            'apiKey' => config('services.supabase.anon_key'),
            'Content-Type' => 'application/json',
        ])->post($url . $path, $body);
    }
}
