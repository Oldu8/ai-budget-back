<?php

namespace Tests;

use App\Enums\PlanType;
use App\Http\Middleware\AuthenticateAppUser;
use App\Models\AppUser;
use App\Models\AppUserSettings;
use App\Models\Category;
use App\Models\Plan;
use App\Models\Status;
use App\Models\UserSubscription;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
    /**
     * Return a test client authenticated as the given AppUser,
     * with the JWT auth middleware bypassed.
     */
    protected function apiAs(AppUser $user): static
    {
        return $this
            ->withoutMiddleware(AuthenticateAppUser::class)
            ->actingAs($user, 'app');
    }

    protected function makeAppUser(): AppUser
    {
        return AppUser::create([
            'email' => Str::random(8).'@test.com',
            'password' => 'password123',
        ]);
    }

    /** Creates a parent + child category and returns the child (leaf). */
    protected function makeCategory(): Category
    {
        $parent = Category::create([
            'name' => 'Expenses',
            'label' => 'Expenses',
        ]);

        return Category::create([
            'name' => 'Subscriptions',
            'label' => 'Subscriptions',
            'category_id' => $parent->id,
        ]);
    }

    protected function makePlan(PlanType $type = PlanType::Free, int $checkLimit = 10): Plan
    {
        return Plan::create([
            'type' => $type,
            'price' => 0,
            'label' => $type->value,
            'check_limit' => $checkLimit,
        ]);
    }

    protected function makeSettings(AppUser $user, string $currency = 'usd', string $language = 'en', string $timezone = 'UTC'): AppUserSettings
    {
        return AppUserSettings::create([
            'app_user_id' => $user->id,
            'currency' => $currency,
            'language' => $language,
            'timezone' => $timezone,
        ]);
    }

    protected function subscribe(AppUser $user, Plan $plan, int $usedChecks = 0): UserSubscription
    {
        $status = Status::firstOrCreate(['name' => 'active']);

        return UserSubscription::create([
            'app_user_id' => $user->id,
            'plan_id' => $plan->id,
            'status_id' => $status->id,
            'used_checks_count' => $usedChecks,
            'current_period_end' => now()->addMonth(),
            'cancel_at_period_end' => false,
        ]);
    }
}
