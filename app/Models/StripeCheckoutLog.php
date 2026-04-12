<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class StripeCheckoutLog extends Model
{
    protected $fillable = [
        'stripe_checkout_session_id',
        'stripe_customer_id',
        'app_user_id',
        'plan_type',
        'currency',
    ];

    /** @return BelongsTo<AppUser, $this> */
    public function appUser(): BelongsTo
    {
        return $this->belongsTo(AppUser::class, 'app_user_id');
    }
}
