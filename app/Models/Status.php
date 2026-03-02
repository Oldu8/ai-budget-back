<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Status extends Model
{
    public $timestamps = false;

    protected $fillable = ['name'];

    public function userSubscriptions(): HasMany
    {
        return $this->hasMany(UserSubscription::class, 'status_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'status_id');
    }
}
