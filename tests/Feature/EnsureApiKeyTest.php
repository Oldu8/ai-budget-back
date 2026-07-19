<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class EnsureApiKeyTest extends TestCase
{
    public function test_allows_request_when_no_secret_configured_outside_production(): void
    {
        config(['services.api_secret' => '']);

        $this->getJson('/api/health')->assertOk();
    }

    public function test_returns_401_when_secret_configured_and_key_missing(): void
    {
        config(['services.api_secret' => 'super-secret']);

        $this->getJson('/api/health')->assertUnauthorized()
            ->assertJson(['message' => 'Invalid or missing API key']);
    }

    public function test_returns_401_when_key_is_wrong(): void
    {
        config(['services.api_secret' => 'super-secret']);

        $this->getJson('/api/health', ['X-API-Key' => 'wrong'])->assertUnauthorized();
    }

    public function test_allows_request_when_key_matches(): void
    {
        config(['services.api_secret' => 'super-secret']);

        $this->getJson('/api/health', ['X-API-Key' => 'super-secret'])->assertOk();
    }

    public function test_aborts_when_secret_is_not_configured_in_production(): void
    {
        config(['services.api_secret' => '']);
        $this->app['env'] = 'production';

        $this->getJson('/api/health')->assertServerError();
    }
}
