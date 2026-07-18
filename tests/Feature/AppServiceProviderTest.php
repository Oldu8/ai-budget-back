<?php

declare(strict_types=1);

namespace Tests\Feature;

use OpenAI\Contracts\ClientContract;
use Tests\TestCase;

final class AppServiceProviderTest extends TestCase
{
    public function test_openai_scan_binding_resolves_a_real_client_configured_for_gemini(): void
    {
        // No instance() override here (unlike ScanControllerTest), so this actually
        // executes the singleton factory closure registered in AppServiceProvider.
        $client = app('openai.scan');

        $this->assertInstanceOf(ClientContract::class, $client);
    }
}
