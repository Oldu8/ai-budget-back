<?php

namespace App\Http\Middleware;

use App\Models\AppUser;
use App\Services\FeatureAccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureFeatureAccess
{
    public function __construct(
        private FeatureAccessService $featureAccess,
    ) {}

    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $user = $request->user();
        if (!$user instanceof AppUser) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $result = $this->featureAccess->canAccess($user, $feature);
        if (!$result->allowed) {
            return response()->json(['message' => $result->message ?? 'Access denied.'], 403);
        }

        return $next($request);
    }
}
