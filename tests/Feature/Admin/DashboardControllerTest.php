<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\PlanType;
use App\Models\Status;
use App\Models\User;
use App\Models\UserSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class DashboardControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_shows_user_and_active_subscription_counts(): void
    {
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@test.com', 'password' => 'password123']);
        $plan = $this->makePlan(PlanType::Basic);
        $active = Status::firstOrCreate(['name' => 'active']);
        $canceled = Status::firstOrCreate(['name' => 'canceled']);

        $user1 = $this->makeAppUser();
        $user2 = $this->makeAppUser();
        $this->makeAppUser(); // no subscription at all

        UserSubscription::create([
            'app_user_id' => $user1->id, 'plan_id' => $plan->id, 'status_id' => $active->id,
            'current_period_end' => now()->addMonth(), 'cancel_at_period_end' => false, 'used_checks_count' => 0,
        ]);
        UserSubscription::create([
            'app_user_id' => $user2->id, 'plan_id' => $plan->id, 'status_id' => $canceled->id,
            'current_period_end' => now()->subMonth(), 'cancel_at_period_end' => false, 'used_checks_count' => 0,
        ]);

        $response = $this->actingAs($admin)->get('/admin');

        $response->assertOk()
            ->assertViewIs('admin.dashboard')
            ->assertViewHas('usersCount', 3)
            ->assertViewHas('activeSubscriptionsCount', 1);
    }

    public function test_dashboard_requires_authentication(): void
    {
        $this->get('/admin')->assertRedirect(route('login'));
    }
}
