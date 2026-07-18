<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\PlanType;
use App\Models\Status;
use App\Models\User;
use App\Models\UserSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SubscriptionControllerTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create(['name' => 'Admin', 'email' => 'admin@test.com', 'password' => 'password123']);
    }

    public function test_index_lists_all_subscriptions(): void
    {
        $status = Status::firstOrCreate(['name' => 'active']);
        $basic = $this->makePlan(PlanType::Basic);
        $plus = $this->makePlan(PlanType::Plus);

        UserSubscription::create([
            'app_user_id' => $this->makeAppUser()->id, 'plan_id' => $basic->id, 'status_id' => $status->id,
            'current_period_end' => now()->addMonth(), 'cancel_at_period_end' => false, 'used_checks_count' => 0,
        ]);
        UserSubscription::create([
            'app_user_id' => $this->makeAppUser()->id, 'plan_id' => $plus->id, 'status_id' => $status->id,
            'current_period_end' => now()->addMonths(2), 'cancel_at_period_end' => false, 'used_checks_count' => 0,
        ]);

        $response = $this->actingAs($this->admin())->get('/admin/subscriptions');

        $response->assertOk()->assertViewIs('admin.subscriptions.index');
        $this->assertCount(2, $response->viewData('subscriptions'));
    }

    public function test_index_filters_by_plan_id(): void
    {
        $status = Status::firstOrCreate(['name' => 'active']);
        $basic = $this->makePlan(PlanType::Basic);
        $plus = $this->makePlan(PlanType::Plus);

        UserSubscription::create([
            'app_user_id' => $this->makeAppUser()->id, 'plan_id' => $basic->id, 'status_id' => $status->id,
            'current_period_end' => now()->addMonth(), 'cancel_at_period_end' => false, 'used_checks_count' => 0,
        ]);
        UserSubscription::create([
            'app_user_id' => $this->makeAppUser()->id, 'plan_id' => $plus->id, 'status_id' => $status->id,
            'current_period_end' => now()->addMonths(2), 'cancel_at_period_end' => false, 'used_checks_count' => 0,
        ]);

        $response = $this->actingAs($this->admin())->get("/admin/subscriptions?plan_id={$basic->id}");

        $subscriptions = $response->viewData('subscriptions');
        $this->assertCount(1, $subscriptions);
        $this->assertSame($basic->id, $subscriptions->first()->plan_id);
    }

    public function test_requires_authentication(): void
    {
        $this->get('/admin/subscriptions')->assertRedirect(route('login'));
    }
}
