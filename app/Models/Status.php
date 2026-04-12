<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Status extends Model
{
    public $timestamps = false;

    protected $fillable = ['name'];

    /** @return HasMany<UserSubscription, $this> */
    public function userSubscriptions(): HasMany
    {
        return $this->hasMany(UserSubscription::class, 'status_id');
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'status_id');
    }
}
