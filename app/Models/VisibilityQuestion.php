<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VisibilityQuestion extends Model
{
    protected $fillable = [
        'visibility_tag',
        'question_key',
        'type',
        'translation_key',
        'order',
        'is_active',
    ];

    protected $casts = [
        'order' => 'integer',
        'is_active' => 'boolean',
    ];
}
