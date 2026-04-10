<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StripeCheckoutLog extends Model
{
    protected $fillable = [
        'stripe_checkout_session_id',
        'stripe_customer_id',
        'app_user_id',
        'plan_type',
        'currency',
    ];

    public function appUser(): BelongsTo
    {
        return $this->belongsTo(AppUser::class, 'app_user_id');
    }
}
