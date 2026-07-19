<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PlanType;
use App\Models\Status;
use App\Models\UserSubscription;
use App\Services\EnsureDefaultSubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class EnsureDefaultSubscriptionServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): EnsureDefaultSubscriptionService
    {
        return app(EnsureDefaultSubscriptionService::class);
    }

    public function test_creates_free_subscription_when_user_has_none(): void
    {
        $user = $this->makeAppUser();
        $this->makePlan(PlanType::Free);
        Status::firstOrCreate(['name' => 'active']);

        $this->service()->ensureFreeSubscription($user);

        $this->assertDatabaseCount('user_subscriptions', 1);
        $this->assertDatabaseHas('user_subscriptions', ['app_user_id' => $user->id]);
    }

    public function test_does_nothing_when_user_already_has_an_active_paid_subscription(): void
    {
        $user = $this->makeAppUser();
        $this->makePlan(PlanType::Free);
        $this->subscribe($user, $this->makePlan(PlanType::Plus));

        $this->service()->ensureFreeSubscription($user);

        $this->assertDatabaseCount('user_subscriptions', 1);
    }

    public function test_does_nothing_when_user_already_has_an_active_free_subscription(): void
    {
        $user = $this->makeAppUser();
        $freePlan = $this->makePlan(PlanType::Free);
        $status = Status::firstOrCreate(['name' => 'active']);
        UserSubscription::create([
            'app_user_id' => $user->id,
            'plan_id' => $freePlan->id,
            'status_id' => $status->id,
            'payment_subscription_id' => null,
            'used_checks_count' => 0,
            'scans_reset_at' => now()->addDays(30),
            'current_period_end' => null,
            'cancel_at_period_end' => false,
        ]);

        $this->service()->ensureFreeSubscription($user);

        $this->assertDatabaseCount('user_subscriptions', 1);
    }
}
