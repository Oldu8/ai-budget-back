<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class Post extends Model
{
    /** Supported marketing-site locales. */
    public const LOCALES = ['en', 'uk', 'es'];

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    protected $fillable = [
        'locale',
        'translation_group_id',
        'slug',
        'title',
        'excerpt',
        'body',
        'cover_image',
        'meta_title',
        'meta_description',
        'status',
        'published_at',
    ];

    protected $casts = [
        'published_at' => 'datetime',
    ];

    /** Only posts visible to the public. */
    public function scopePublished(Builder $query): Builder
    {
        return $query
            ->where('status', self::STATUS_PUBLISHED)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED
            && $this->published_at !== null
            && $this->published_at->isPast();
    }

    /** Estimated reading time in minutes, derived from the body. */
    public function readingTimeMinutes(): int
    {
        $words = str_word_count(strip_tags((string) $this->body));

        return max(1, (int) ceil($words / 200));
    }

    public function metaTitleValue(): string
    {
        return $this->meta_title ?: $this->title;
    }

    public function metaDescriptionValue(): ?string
    {
        return $this->meta_description ?: $this->excerpt;
    }

    public function coverUrl(): ?string
    {
        // Root-relative URL so images resolve on any host/port regardless of APP_URL.
        return $this->cover_image ? '/storage/'.ltrim($this->cover_image, '/') : null;
    }
}
