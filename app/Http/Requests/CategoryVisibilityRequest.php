<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CategoryVisibilityRequest extends FormRequest
{
    public function rules(): array
    {
        $tagsConfig = require database_path('data/visibility_tags.php');
        $tags = array_column($tagsConfig, 'tag');

        return [
            'visibility_tag' => ['required', 'string', 'in:' . implode(',', $tags)],
            'enabled' => ['required', 'boolean'],
        ];
    }
}
