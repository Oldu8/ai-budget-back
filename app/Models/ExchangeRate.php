<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A direct cross rate on a date: 1 unit of `from_currency` equals `rate` units
 * of `to_currency`. Stored as expanded pairs so conversion is a single lookup.
 *
 * @property string $from_currency ISO 4217 code
 * @property string $to_currency ISO 4217 code
 * @property Carbon $date
 * @property string $rate `to_currency` units per 1 unit of `from_currency`
 * @property string $source 'ecb', 'nbu', or 'ecb+nbu'
 */
final class ExchangeRate extends Model
{
    protected $fillable = [
        'from_currency',
        'to_currency',
        'date',
        'rate',
        'source',
    ];

    protected $casts = [
        'date' => 'date',
        'rate' => 'decimal:12',
    ];
}
