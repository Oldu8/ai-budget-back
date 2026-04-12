<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class UserSubscription extends Model
{
    protected $table = 'user_subscriptions';

    protected $fillable = [
        'app_user_id',
        'plan_id',
        'payment_subscription_id',
        'status_id',
        'trial_start',
        'trial_end',
        'current_period_end',
        'cancel_at_period_end',
        'used_checks_count',
    ];

    protected $casts = [
        'trial_start' => 'datetime',
        'trial_end' => 'datetime',
        'current_period_end' => 'datetime',
    ];

    /** PG returns 't'/'f' strings; normalize to PHP bool. */
    protected function cancelAtPeriodEnd(): Attribute
    {
        return Attribute::make(
            get: fn (mixed $value) => filter_var($value, FILTER_VALIDATE_BOOLEAN),
        );
    }

    /** @return BelongsTo<AppUser, $this> */
    public function appUser(): BelongsTo
    {
        return $this->belongsTo(AppUser::class, 'app_user_id');
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /** @return BelongsTo<Status, $this> */
    public function status(): BelongsTo
    {
        return $this->belongsTo(Status::class, 'status_id');
    }
}
