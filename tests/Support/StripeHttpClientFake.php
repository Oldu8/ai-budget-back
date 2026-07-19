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
    /** Every request made, as ['method' => …, 'url' => …, 'params' => …] — for asserting request payloads. */
    public array $requests = [];

    /** @var array<string, int> path fragment => how many times it has matched so far */
    private array $callCounts = [];

    /**
     * @param  array<string, array<string, mixed>|callable(array<string, mixed> $params, int $callNumber): array{body?: array<string, mixed>, status?: int}>  $responses
     *                                                                                                                                                                    URL path fragment => response body, or a callable for per-call-number/param-dependent responses
     *                                                                                                                                                                    (e.g. fail on the 1st call, succeed on the 2nd — see the portal flow_data retry test).
     */
    public function __construct(
        private array $responses = [],
    ) {}

    /** @return array{string, int, array<string, string>} */
    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null): array
    {
        $this->requests[] = ['method' => $method, 'url' => $absUrl, 'params' => $params];

        foreach ($this->responses as $pathFragment => $response) {
            if (! str_contains($absUrl, $pathFragment)) {
                continue;
            }

            $this->callCounts[$pathFragment] = ($this->callCounts[$pathFragment] ?? 0) + 1;

            if (is_callable($response)) {
                $result = $response($params, $this->callCounts[$pathFragment]);

                return [json_encode($result['body'] ?? [], JSON_THROW_ON_ERROR), $result['status'] ?? 200, []];
            }

            return [json_encode($response, JSON_THROW_ON_ERROR), 200, []];
        }

        return [json_encode(['error' => ['type' => 'invalid_request_error', 'message' => "No fake response for {$absUrl}"]]), 400, []];
    }
}
