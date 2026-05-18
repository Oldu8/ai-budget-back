<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Log;

/** @property \App\Models\AppUserSettings|null $settings */
final class AppUser extends Authenticatable
{
    use SoftDeletes;

    protected $table = 'app_users';

    protected $fillable = [
        'supabase_user_id',
        'email',
        'first_name',
        'last_name',
        'email_verified_at',
        'payment_customer_id',
    ];

    protected $hidden = [
        'supabase_user_id',
        'payment_customer_id',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
    ];

    /** @return HasOne<AppUserSettings, $this> */
    public function settings(): HasOne
    {
        return $this->hasOne(AppUserSettings::class, 'app_user_id');
    }

    /** @return HasMany<Transaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'app_user_id');
    }

    /** @return HasMany<RecurringExpense, $this> */
    public function recurringExpenses(): HasMany
    {
        return $this->hasMany(RecurringExpense::class, 'app_user_id');
    }

    /** @return HasMany<UserSubscription, $this> */
    public function userSubscriptions(): HasMany
    {
        return $this->hasMany(UserSubscription::class, 'app_user_id');
    }

    /**
     * Current active subscription.
     * Returns active/trialing subscriptions, and also canceled subscriptions
     * that are still within their paid billing period (current_period_end > now).
     */
    public function activeSubscription(): ?UserSubscription
    {
        $subscription = $this->userSubscriptions()
            ->where(fn ($q) => $q
                ->whereHas('status', fn ($sq) => $sq->whereIn('name', ['active', 'trialing']))
                ->orWhere(fn ($sq) => $sq
                    ->whereHas('status', fn ($ssq) => $ssq->where('name', 'canceled'))
                    ->where('current_period_end', '>', now())
                )
            )
            ->latest('current_period_end')
            ->first();

        if ($subscription !== null) {
            Log::debug('AppUser: activeSubscription resolved', [
                'user_id' => $this->id,
                'subscription_id' => $subscription->id,
                'current_period_end' => $subscription->current_period_end,
                'payment_subscription_id' => $subscription->payment_subscription_id,
            ]);
        }

        return $subscription;
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'app_user_id');
    }

    /** @return HasMany<AppUserCategorySetting, $this> */
    public function disabledCategorySettings(): HasMany
    {
        return $this->hasMany(AppUserCategorySetting::class, 'app_user_id');
    }
}
