<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlanController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $currency = strtolower($user->settings?->currency ?? 'usd');
        $prices = config('services.stripe.prices', []);

        $plans = Plan::orderByRaw("CASE type WHEN 'free' THEN 0 WHEN 'basic' THEN 1 WHEN 'plus' THEN 2 WHEN 'premium' THEN 3 END")
            ->get()
            ->map(function (Plan $plan) use ($currency, $prices) {
                $type = $plan->type->value;
                $priceId = $prices[$type][$currency] ?? $prices[$type]['usd'] ?? null;

                return [
                    'type' => $type,
                    'label' => $plan->label,
                    'price' => $plan->price,
                    'currency' => $currency,
                    'price_id' => $priceId,
                    'check_limit' => $plan->check_limit,
                    'description' => $plan->description,
                ];
            });

        return response()->json($plans);
    }
}
