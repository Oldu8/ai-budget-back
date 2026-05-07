<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class AnalyticsDashboardRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'month' => [
                'required',
                'string',
                'date_format:Y-m',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'month.date_format' => 'The month must be a valid month in YYYY-MM format.',
        ];
    }
}
