<?php

namespace App\Models;

use App\Enums\TransactionCreatedBy;
use App\Enums\TransactionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transaction extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'app_user_id',
        'category_id',
        'amount',
        'date',
        'type',
        'created_by',
        'raw_ai_data',
        'confirmed_at',
        'img_path',
    ];

    protected $casts = [
        'date' => 'date',
        'type' => TransactionType::class,
        'created_by' => TransactionCreatedBy::class,
        'confirmed_at' => 'datetime',
        'raw_ai_data' => 'array',
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
