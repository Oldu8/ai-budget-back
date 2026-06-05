<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AppUserIdentity extends Model
{
    protected $table = 'app_user_identities';

    protected $fillable = [
        'app_user_id',
        'provider',
        'provider_user_id',
    ];

    /** @return BelongsTo<AppUser, $this> */
    public function appUser(): BelongsTo
    {
        return $this->belongsTo(AppUser::class, 'app_user_id');
    }
}
