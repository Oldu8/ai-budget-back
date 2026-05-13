<?php

declare(strict_types=1);

namespace Tests\Support;

use Stripe\HttpClient\ClientInterface;

/**
 * Fake Stripe HTTP client for tests that need checkout/portal/customer API responses.
 *
 * Usage:
 *   \Stripe\ApiRequestor::setHttpClient(new StripeHttpClientFake([
 *       '/v1/customers' => ['id' => 'cus_test', 'object' => 'customer'],
 *       '/v1/checkout/sessions' => ['id' => 'cs_test', 'url' => 'https://checkout.stripe.com/test'],
 *       '/v1/billing_portal/sessions' => ['id' => 'bps_test', 'url' => 'https://billing.stripe.com/test'],
 *   ]));
 */
final class StripeHttpClientFake implements ClientInterface
{
    /** @param array<string, array<string, mixed>> $responses URL path fragment → response body */
    public function __construct(
        private array $responses = [],
    ) {}

    /** @return array{string, int, array<string, string>} */
    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null): array
    {
        foreach ($this->responses as $pathFragment => $body) {
            if (str_contains($absUrl, $pathFragment)) {
                return [json_encode($body, JSON_THROW_ON_ERROR), 200, []];
            }
        }

        return [json_encode(['error' => ['type' => 'invalid_request_error', 'message' => "No fake response for {$absUrl}"]]), 400, []];
    }
}
