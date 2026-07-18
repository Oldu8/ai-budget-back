<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\PlanType;
use App\Models\Status;
use App\Models\User;
use App\Models\UserSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AppUserControllerTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create(['name' => 'Admin', 'email' => 'admin@test.com', 'password' => 'password123']);
    }

    public function test_index_lists_users(): void
    {
        $this->makeAppUser();
        $this->makeAppUser();

        $response = $this->actingAs($this->admin())->get('/admin/users');

        $response->assertOk()->assertViewIs('admin.users.index');
        $this->assertCount(2, $response->viewData('users'));
    }

    public function test_show_displays_user_with_settings_and_subscriptions(): void
    {
        $user = $this->makeAppUser();
        $this->makeSettings($user, currency: 'EUR');
        $plan = $this->makePlan(PlanType::Basic);
        $status = Status::firstOrCreate(['name' => 'active']);
        UserSubscription::create([
            'app_user_id' => $user->id, 'plan_id' => $plan->id, 'status_id' => $status->id,
            'current_period_end' => now()->addMonth(), 'cancel_at_period_end' => false, 'used_checks_count' => 0,
        ]);

        $response = $this->actingAs($this->admin())->get("/admin/users/{$user->id}");

        $response->assertOk()->assertViewIs('admin.users.show');
        $viewUser = $response->viewData('user');
        $this->assertSame($user->id, $viewUser->id);
        $this->assertNotNull($viewUser->settings);
        $this->assertCount(1, $viewUser->userSubscriptions);
    }

    public function test_show_returns_404_for_unknown_user(): void
    {
        $this->actingAs($this->admin())->get('/admin/users/999999')->assertNotFound();
    }

    public function test_requires_authentication(): void
    {
        $this->get('/admin/users')->assertRedirect(route('login'));
    }
}
