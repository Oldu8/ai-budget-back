<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    protected $fillable = ['name', 'category_id', 'label', 'visibility_tag'];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'category_id');
    }

    public function transactionItems(): HasMany
    {
        return $this->hasMany(TransactionItem::class);
    }

    /** Leaf (sub)categories available for AI scan and receipt updates: excludes user-disabled categories. */
    public static function leafCategoriesForUser(AppUser $user): Collection
    {
        $disabledIds = $user->disabledCategorySettings()->pluck('category_id')->all();

        return static::query()
            ->whereNotNull('category_id')
            ->with('parent')
            ->when(! empty($disabledIds), fn ($q) => $q->whereNotIn('id', $disabledIds))
            ->get();
    }
}
