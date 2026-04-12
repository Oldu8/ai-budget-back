<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;

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

    /** Current active subscription (filters by status name 'active' or 'trialing'). */
    public function activeSubscription(): ?UserSubscription
    {
        return $this->userSubscriptions()
            ->whereHas('status', fn ($q) => $q->whereIn('name', ['active', 'trialing']))
            ->latest('current_period_end')
            ->first();
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
