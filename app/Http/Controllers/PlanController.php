<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Stripe\Price as StripePrice;
use Stripe\Stripe;

class PlanController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $currency = strtolower($user->settings?->currency ?? 'usd'); // @phpstan-ignore nullsafe.neverNull
        $prices = config('services.stripe.prices', []);

        $plans = Plan::orderByRaw("CASE type WHEN 'free' THEN 0 WHEN 'basic' THEN 1 WHEN 'plus' THEN 2 WHEN 'premium' THEN 3 END")
            ->get()
            ->map(function (Plan $plan) use ($currency, $prices) {
                $type = $plan->type->value;
                $configuredForUserCurrency = ! empty($prices[$type][$currency]);
                $priceId = $prices[$type][$currency] ?? $prices[$type]['usd'] ?? null;
                // Currency the matched config price is denominated in (user currency or the usd fallback).
                $priceCurrency = $configuredForUserCurrency ? $currency : 'usd';

                // Amount/currency of the actual Stripe price the user would be charged —
                // the DB `plans.price` is only a fallback and can drift from Stripe.
                $stripePrice = $priceId ? $this->stripePrice($priceId) : null;

                return [
                    'type' => $type,
                    'label' => $plan->label,
                    'price' => $stripePrice['unit_amount'] ?? $plan->price,
                    'currency' => $stripePrice['currency'] ?? $priceCurrency,
                    'price_id' => $priceId,
                    'check_limit' => $plan->check_limit,
                    'description' => $plan->description,
                ];
            });

        return response()->json($plans);
    }

    /**
     * Fetch unit_amount/currency for a Stripe price, cached — prices change rarely
     * and this endpoint is hit on every paywall open.
     *
     * @return array{unit_amount: int|null, currency: string|null}|null
     */
    private function stripePrice(string $priceId): ?array
    {
        try {
            return Cache::remember("stripe.price.{$priceId}", now()->addHours(6), function () use ($priceId) {
                Stripe::setApiKey(config('services.stripe.secret'));
                $price = StripePrice::retrieve($priceId);

                return [
                    'unit_amount' => $price->unit_amount,
                    'currency' => $price->currency,
                ];
            });
        } catch (\Throwable $e) {
            Log::warning('Stripe price lookup failed; falling back to DB plan price', [
                'price_id' => $priceId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
