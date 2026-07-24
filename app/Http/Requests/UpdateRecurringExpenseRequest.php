<?php

namespace App\Http\Requests;

use App\Models\Category;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateRecurringExpenseRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'category_id' => ['sometimes', 'integer', 'exists:categories,id'],
            'amount' => ['sometimes', 'integer', 'min:1', 'max:100000000'],
            'label' => ['sometimes', 'nullable', 'string', 'max:255'],
            'starts_at' => ['sometimes', 'date'],
            'frequency' => ['sometimes', 'in:weekly,monthly,yearly'],
            'interval' => ['sometimes', 'integer', 'min:1'],
            'ends_at' => ['sometimes', 'nullable', 'date', 'after_or_equal:starts_at'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $user = $this->user();
            if (! $user) {
                return;
            }

            if (! $this->has('category_id')) {
                return;
            }

            $categoryId = (int) $this->input('category_id');
            if (! $categoryId) {
                return;
            }

            $isLevelTwo = Category::whereKey($categoryId)
                ->whereNotNull('category_id')
                ->exists();

            if (! $isLevelTwo) {
                $validator->errors()->add('category_id', 'Category must be a Level 2 category.');

                return;
            }

            $isDisabled = $user->disabledCategorySettings()
                ->where('category_id', $categoryId)
                ->exists();

            if ($isDisabled) {
                $validator->errors()->add('category_id', 'Category is disabled for this user.');
            }
        });
    }
}
