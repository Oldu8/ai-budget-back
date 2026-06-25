<?php

namespace App\Http\Requests;

use App\Enums\TransactionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTransactionRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'total_amount' => ['sometimes', 'integer', 'min:1'],
            'date' => ['sometimes', 'date', 'after_or_equal:'.config('rates.earliest_date')],
            'type' => ['sometimes', Rule::enum(TransactionType::class)],
            'category_id' => ['sometimes', 'exists:categories,id'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'date.after_or_equal' => 'Transactions before '.config('rates.earliest_date').' are not supported.',
        ];
    }
}
