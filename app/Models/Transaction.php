<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TransactionCreatedBy;
use App\Enums\TransactionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Virtual properties added via selectRaw/addSelect in query results.
 *
 * @property int|string|null $amount Raw amount from transaction_items join
 * @property string|null $category_label Category label from categories join
 * @property int|string|null $category_id Category id from joined subcat
 * @property int|string|null $subcategory_id Subcategory id from joined transaction_items
 * @property int|string|null $total Aggregated total from SUM() selectRaw
 */
final class Transaction extends Model
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

    /** @return BelongsTo<AppUser, $this> */
    public function appUser(): BelongsTo
    {
        return $this->belongsTo(AppUser::class, 'app_user_id');
    }

    /** @return BelongsTo<RecurringExpense, $this> */
    public function recurringExpense(): BelongsTo
    {
        return $this->belongsTo(RecurringExpense::class);
    }

    /** @return HasMany<TransactionItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(TransactionItem::class);
    }
}
