<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;

/** @property AppUserSettings|null $settings */
final class AppUser extends Authenticatable
{
    use SoftDeletes;

    protected $table = 'app_users';

    protected $fillable = [
        'email',
        'password',
        'first_name',
        'last_name',
        'email_verified_at',
        'payment_customer_id',
    ];

    protected $hidden = [
        'password',
        'payment_customer_id',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];

    /** @return HasOne<AppUserSettings, $this> */
    public function settings(): HasOne
    {
        return $this->hasOne(AppUserSettings::class, 'app_user_id');
    }

    /** @return HasMany<AppUserIdentity, $this> */
    public function identities(): HasMany
    {
        return $this->hasMany(AppUserIdentity::class, 'app_user_id');
    }

    /** @return HasMany<AppUserRefreshToken, $this> */
    public function refreshTokens(): HasMany
    {
        return $this->hasMany(AppUserRefreshToken::class, 'app_user_id');
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
            ->orderByRaw('current_period_end DESC NULLS LAST')
            ->first();

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
