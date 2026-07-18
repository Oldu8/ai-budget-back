<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\EnsureFeatureAccess;
use App\Services\FeatureAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

final class EnsureFeatureAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_401_when_no_app_user_is_authenticated(): void
    {
        $middleware = new EnsureFeatureAccess(app(FeatureAccessService::class));
        $request = Request::create('/api/recurring-expenses', 'GET');

        $response = $middleware->handle($request, fn ($req) => response('should not reach here'), 'recurring_expenses');

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame(['message' => 'Unauthorized'], json_decode($response->getContent(), true));
    }
}
