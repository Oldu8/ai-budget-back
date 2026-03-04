<?php

namespace App\Http\Requests;

use App\Enums\TransactionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTransactionRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'total_amount' => ['required', 'integer', 'min:1'],
            'date' => ['required', 'date'],
            'type' => ['required', Rule::enum(TransactionType::class)],
            'category_id' => ['required', 'exists:categories,id'],
        ];
    }
}
