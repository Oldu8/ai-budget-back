<?php

namespace App\Http\Requests;

use App\Models\Category;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreRecurringExpenseRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'amount' => ['required', 'integer', 'min:1', 'max:100000000'],
            'label' => ['nullable', 'string', 'max:255'],
            'starts_at' => ['required', 'date'],
            'frequency' => ['required', 'in:weekly,monthly,yearly'],
            'interval' => ['nullable', 'integer', 'min:1'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $user = $this->user();
            if (! $user) {
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
