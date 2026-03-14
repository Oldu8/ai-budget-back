<?php

namespace App\Http\Requests;

use App\Services\VisibilityQuestionService;
use Illuminate\Foundation\Http\FormRequest;

class CategoryVisibilityRequest extends FormRequest
{
    public function rules(): array
    {
        $allowedTags = app(VisibilityQuestionService::class)->getAllowedTags();

        return [
            'visibility_tag' => ['required', 'string', 'in:' . implode(',', $allowedTags)],
            'enabled' => ['required', 'boolean'],
        ];
    }
}
