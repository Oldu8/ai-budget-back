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
            'amount' => ['sometimes', 'integer', 'min:1'],
            'date' => ['sometimes', 'date'],
            'type' => ['sometimes', Rule::enum(TransactionType::class)],
            'category_id' => ['sometimes', 'exists:categories,id'],
        ];
    }
}
