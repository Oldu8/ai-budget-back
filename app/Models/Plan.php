<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PlanType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Plan extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'type',
        'payment_price_id',
        'price',
        'label',
        'description',
        'check_limit',
    ];

    protected $casts = [
        'type' => PlanType::class,
    ];

    /** @return HasMany<UserSubscription, $this> */
    public function userSubscriptions(): HasMany
    {
        return $this->hasMany(UserSubscription::class);
    }
}
