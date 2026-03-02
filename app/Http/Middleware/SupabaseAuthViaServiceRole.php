<?php

namespace App\Http\Middleware;

use App\Models\AppUser;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;

class SupabaseAuthViaServiceRole
{
    public function handle(Request $request, Closure $next)
    {
        $authHeader = $request->header('Authorization');

        if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $accessToken = substr($authHeader, 7);

        $supabaseUrl = rtrim(config('services.supabase.url'), '/');
        $serviceRoleKey = config('services.supabase.service_role');

        if (!$supabaseUrl || !$serviceRoleKey) {
            return response()->json(['message' => 'Server auth misconfigured'], 500);
        }

        // запрос к Supabase /auth/v1/user
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $accessToken,
            'apiKey'        => $serviceRoleKey,
        ])->get($supabaseUrl . '/auth/v1/user');

        if ($response->failed()) {
            return response()->json(['message' => 'Invalid or expired token'], 401);
        }

        $userData = $response->json();

        $supabaseUserId = $userData['id'] ?? null;
        $email = $userData['email'] ?? null;
        $emailConfirmedAt = $userData['email_confirmed_at'] ?? null;

        if (!$supabaseUserId || !$email) {
            return response()->json(['message' => 'Invalid user data'], 401);
        }

        // создаём/обновляем app_user
        $user = AppUser::firstOrCreate(
            ['supabase_user_id' => $supabaseUserId],
            ['email' => $email]
        );

        // синк email, если он поменялся в Supabase
        if ($user->email !== $email) {
            $user->email = $email;
        }

        if ($emailConfirmedAt && !$user->email_verified_at) {
            $user->email_verified_at = $emailConfirmedAt;
        }

        $user->save();

        Auth::setUser($user);

        return $next($request);
    }
}
