<?php

namespace App\Http\Requests;

use App\Enums\TransactionCreatedBy;
use App\Models\Category;
use App\Models\Transaction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class UpdateReceiptTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Transaction $transaction */
        $transaction = $this->route('transaction');
        $allowed = $this->user()->transactions()
            ->where('created_by', TransactionCreatedBy::Ai)
            ->whereNull('confirmed_at')
            ->whereKey($transaction->id)
            ->exists();

        if (! $allowed) {
            throw new NotFoundHttpException;
        }

        return true;
    }

    public function rules(): array
    {
        /** @var Transaction $transaction */
        $transaction = $this->route('transaction');
        $allowedIds = Category::leafCategoriesForUser($this->user())->pluck('id')->all();

        return [
            'date' => ['required', 'date'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['sometimes', 'integer', Rule::exists('transaction_items', 'id')->where('transaction_id', $transaction->id)],
            'items.*.label' => ['nullable', 'string', 'max:500'],
            'items.*.amount' => ['required', 'integer', 'min:1'],
            'items.*.category_id' => ['required', 'integer', Rule::in($allowedIds)],
        ];
    }
}
