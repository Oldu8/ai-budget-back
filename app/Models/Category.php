<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Category extends Model
{
    protected $fillable = ['name', 'category_id', 'label', 'visibility_tag', 'order'];

    /** @return BelongsTo<Category, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    /** @return HasMany<Category, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'category_id');
    }

    /** @return HasMany<TransactionItem, $this> */
    public function transactionItems(): HasMany
    {
        return $this->hasMany(TransactionItem::class);
    }

    /**
     * Leaf (sub)categories available for AI scan and receipt updates: excludes user-disabled categories.
     *
     * @return Collection<int, self>
     */
    public static function leafCategoriesForUser(AppUser $user): Collection
    {
        $disabledIds = $user->disabledCategorySettings()->pluck('category_id')->all();

        return self::query()
            ->whereNotNull('category_id')
            ->with('parent')
            ->when(! empty($disabledIds), fn ($q) => $q->whereNotIn('id', $disabledIds))
            ->get();
    }
}
