<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Builds Stripe-signed webhook payloads for feature tests.
 *
 * Usage:
 *   [$payload, $sig] = StripePayloadBuilder::subscriptionCreated(...);
 *   $this->call('POST', '/api/stripe/webhook', [], [], [],
 *       ['HTTP_STRIPE_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'], $payload);
 */
final class StripePayloadBuilder
{
    // ──────────────────────────────────────────────
    // Public factory methods
    // ──────────────────────────────────────────────

    /** @return array{0: string, 1: string} [rawJson, signatureHeader] */
    public static function checkoutSessionCompleted(
        string $sessionId,
        string $customerId,
        int $appUserId,
        string $planType = 'basic',
        string $currency = 'usd',
    ): array {
        $payload = self::event('checkout.session.completed', [
            'id' => $sessionId,
            'object' => 'checkout.session',
            'customer' => $customerId,
            'client_reference_id' => (string) $appUserId,
            'metadata' => [
                'app_user_id' => (string) $appUserId,
                'plan_type' => $planType,
                'currency' => $currency,
            ],
        ]);

        return [$payload, self::sign($payload)];
    }

    /** @return array{0: string, 1: string} [rawJson, signatureHeader] */
    public static function subscriptionCreated(
        string $customerId,
        string $subscriptionId,
        string $priceId,
        int $currentPeriodEnd,
        string $status = 'active',
    ): array {
        $payload = self::event('customer.subscription.created', self::subscriptionObject(
            $customerId, $subscriptionId, $priceId, $currentPeriodEnd, $status,
        ));

        return [$payload, self::sign($payload)];
    }

    /** @return array{0: string, 1: string} [rawJson, signatureHeader] */
    public static function subscriptionUpdated(
        string $customerId,
        string $subscriptionId,
        string $priceId,
        int $currentPeriodEnd,
        string $status = 'active',
        bool $cancelAtPeriodEnd = false,
    ): array {
        $payload = self::event('customer.subscription.updated', self::subscriptionObject(
            $customerId, $subscriptionId, $priceId, $currentPeriodEnd, $status, $cancelAtPeriodEnd,
        ));

        return [$payload, self::sign($payload)];
    }

    /** @return array{0: string, 1: string} [rawJson, signatureHeader] */
    public static function subscriptionDeleted(
        string $customerId,
        string $subscriptionId,
        string $priceId,
        int $currentPeriodEnd,
    ): array {
        $payload = self::event('customer.subscription.deleted', self::subscriptionObject(
            $customerId, $subscriptionId, $priceId, $currentPeriodEnd, 'canceled',
        ));

        return [$payload, self::sign($payload)];
    }

    /** @return array{0: string, 1: string} [rawJson, signatureHeader] */
    public static function invoicePaid(
        string $customerId,
        string $invoiceId,
        string $subscriptionId,
        int $amountPaid = 999,
        string $currency = 'usd',
        string $billingReason = 'subscription_cycle',
    ): array {
        $payload = self::event('invoice.paid', self::invoiceObject(
            $customerId, $invoiceId, $subscriptionId, $amountPaid, $currency, $billingReason,
        ));

        return [$payload, self::sign($payload)];
    }

    /** @return array{0: string, 1: string} [rawJson, signatureHeader] */
    public static function invoicePaymentFailed(
        string $customerId,
        string $invoiceId,
        string $subscriptionId,
    ): array {
        $payload = self::event('invoice.payment_failed', self::invoiceObject(
            $customerId, $invoiceId, $subscriptionId, 0, 'usd', 'subscription_cycle',
        ));

        return [$payload, self::sign($payload)];
    }

    // ──────────────────────────────────────────────
    // Signature helper
    // ──────────────────────────────────────────────

    public static function sign(string $rawJson, ?string $secret = null, ?int $timestamp = null): string
    {
        $secret ??= config('services.stripe.webhook_secret', 'test');
        $timestamp ??= time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$rawJson}", $secret);

        return "t={$timestamp},v1={$signature}";
    }

    // ──────────────────────────────────────────────
    // Private builders
    // ──────────────────────────────────────────────

    /** Wrap an object in a full Stripe event envelope. */
    private static function event(string $type, array $object): string
    {
        return json_encode([
            'id' => 'evt_test_'.substr(md5($type.microtime()), 0, 16),
            'object' => 'event',
            'type' => $type,
            'api_version' => '2024-06-20',
            'created' => time(),
            'data' => ['object' => $object],
        ], JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private static function subscriptionObject(
        string $customerId,
        string $subscriptionId,
        string $priceId,
        int $currentPeriodEnd,
        string $status = 'active',
        bool $cancelAtPeriodEnd = false,
    ): array {
        return [
            'id' => $subscriptionId,
            'object' => 'subscription',
            'customer' => $customerId,
            'status' => $status,
            'cancel_at_period_end' => $cancelAtPeriodEnd,
            'current_period_end' => $currentPeriodEnd,
            'trial_start' => null,
            'trial_end' => null,
            'items' => [
                'object' => 'list',
                'data' => [[
                    'id' => 'si_test_'.substr(md5($subscriptionId), 0, 16),
                    'object' => 'subscription_item',
                    'current_period_end' => $currentPeriodEnd,
                    'price' => [
                        'id' => $priceId,
                        'object' => 'price',
                    ],
                ]],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function invoiceObject(
        string $customerId,
        string $invoiceId,
        string $subscriptionId,
        int $amountPaid,
        string $currency,
        string $billingReason,
    ): array {
        return [
            'id' => $invoiceId,
            'object' => 'invoice',
            'customer' => $customerId,
            'subscription' => $subscriptionId,
            'amount_paid' => $amountPaid,
            'currency' => $currency,
            'billing_reason' => $billingReason,
            'lines' => [
                'object' => 'list',
                'data' => [],
            ],
        ];
    }
}
