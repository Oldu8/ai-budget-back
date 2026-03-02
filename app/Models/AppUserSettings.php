<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppUserSettings extends Model
{
    public $timestamps = false;

    protected $table = 'app_user_settings';

    protected $fillable = [
        'app_user_id',
        'currency',
        'language',
        'timezone',
    ];

    public function appUser(): BelongsTo
    {
        return $this->belongsTo(AppUser::class, 'app_user_id');
    }
}
