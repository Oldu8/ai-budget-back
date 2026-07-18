<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Currency\Providers\LiveRateProvider;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class LiveRateProviderTest extends TestCase
{
    private function provider(): LiveRateProvider
    {
        return app(LiveRateProvider::class);
    }

    public function test_units_per_eur_on_success(): void
    {
        Http::fake([
            'open.er-api.com/*' => Http::response([
                'result' => 'success',
                'rates' => ['EUR' => 1.0, 'USD' => 1.05, 'UAH' => 45.2],
            ]),
        ]);

        $result = $this->provider()->unitsPerEur();

        $this->assertSame(['EUR' => 1.0, 'USD' => 1.05, 'UAH' => 45.2], $result);
    }

    public function test_units_per_eur_returns_empty_when_result_is_not_success(): void
    {
        Http::fake([
            'open.er-api.com/*' => Http::response(['result' => 'error']),
        ]);

        $this->assertSame([], $this->provider()->unitsPerEur());
    }

    public function test_units_per_eur_throws_on_http_failure(): void
    {
        Http::fake([
            'open.er-api.com/*' => Http::response(null, 500),
        ]);

        $this->expectException(RequestException::class);

        $this->provider()->unitsPerEur();
    }
}
