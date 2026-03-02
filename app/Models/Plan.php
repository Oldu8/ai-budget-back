<?php

namespace App\Models;

use App\Enums\PlanType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
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

    public function userSubscriptions(): HasMany
    {
        return $this->hasMany(UserSubscription::class);
    }
}
