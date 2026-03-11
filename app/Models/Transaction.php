<?php

namespace App\Models;

use App\Enums\TransactionCreatedBy;
use App\Enums\TransactionType;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Transaction extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'app_user_id',
        'total_amount',
        'recurring_expense_id',
        'date',
        'type',
        'created_by',
        'raw_ai_data',
        'confirmed_at',
        'recurring_run_at',
        'img_path',
    ];

    protected $casts = [
        'date' => 'date',
        'type' => TransactionType::class,
        'created_by' => TransactionCreatedBy::class,
        'confirmed_at' => 'datetime',
        'raw_ai_data' => 'array',
        'recurring_run_at' => 'datetime',
    ];

    public function appUser(): BelongsTo
    {
        return $this->belongsTo(AppUser::class, 'app_user_id');
    }

    public function recurringExpense(): BelongsTo
    {
        return $this->belongsTo(RecurringExpense::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(TransactionItem::class);
    }
}
