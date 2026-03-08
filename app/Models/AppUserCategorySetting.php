<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppUserCategorySetting extends Model
{
    public $timestamps = false;

    protected $table = 'app_user_category_settings';

    protected $fillable = [
        'app_user_id',
        'category_id',
        'visibility_tag',
    ];

    public function appUser(): BelongsTo
    {
        return $this->belongsTo(AppUser::class, 'app_user_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
