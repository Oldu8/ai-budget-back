<?php

namespace App\Http\Controllers;

use App\Enums\TransactionCreatedBy;
use App\Http\Requests\IndexTransactionRequest;
use App\Http\Requests\StoreTransactionRequest;
use App\Http\Requests\UpdateTransactionRequest;
use App\Http\Resources\TransactionResource;
use App\Models\AppUser;
use App\Models\Transaction;
use App\Models\TransactionItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Manual transaction CRUD (user-created entries, confirmed immediately).
 * AI receipt scan and save flow: {@see ScanController}.
 */
class ManualTransactionController extends Controller
{
    public function index(IndexTransactionRequest $request): JsonResponse
    {
        $filters = $request->validated();

        /** @var AppUser $user */
        $user = $request->user();
        $query = $user->transactions()
            ->whereNotNull('confirmed_at')
            ->with('items.category')
            ->orderByDesc('date')
            ->orderByDesc('created_at');

        if (isset($filters['from'])) {
            $query->where('date', '>=', $filters['from']);
        }
        if (isset($filters['to'])) {
            $query->where('date', '<=', $filters['to']);
        }
        if (isset($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        $transactions = $query->paginate($filters['per_page'] ?? 20);

        return TransactionResource::collection($transactions)->response();
    }

    public function store(StoreTransactionRequest $request): JsonResponse
    {
        /** @var AppUser $user */
        $user = $request->user();
        $totalAmount = $request->validated('total_amount');
        $transaction = Transaction::create([
            'app_user_id' => $user->id,
            'total_amount' => $totalAmount,
            'date' => $request->validated('date'),
            'type' => $request->validated('type'),
            'created_by' => TransactionCreatedBy::User,
            'confirmed_at' => now(),
        ]);

        TransactionItem::create([
            'transaction_id' => $transaction->id,
            'category_id' => $request->validated('category_id'),
            'amount' => $totalAmount,
            'label' => $request->validated('label'),
        ]);

        $transaction->load('items.category');

        return TransactionResource::make($transaction)->response()->setStatusCode(201);
    }

    public function show(Request $request, Transaction $transaction): JsonResponse
    {
        $transaction = $request->user()->transactions()->findOrFail($transaction->id);
        $transaction->load('items.category');

        return TransactionResource::make($transaction)->response();
    }

    public function update(UpdateTransactionRequest $request, Transaction $transaction): JsonResponse
    {
        /** @var Transaction $transaction */
        $transaction = $request->user()->transactions()->findOrFail($transaction->id);

        $validated = $request->validated();
        $transaction->update([
            'total_amount' => $validated['total_amount'] ?? $transaction->total_amount,
            'date' => $validated['date'] ?? $transaction->date,
            'type' => $validated['type'] ?? $transaction->type,
        ]);

        $item = $transaction->items()->first();
        if ($item && (isset($validated['category_id']) || isset($validated['total_amount']))) {
            if (isset($validated['category_id'])) {
                $item->category_id = $validated['category_id'];
            }
            if (isset($validated['total_amount'])) {
                $item->amount = $validated['total_amount'];
            }
            $item->save();
        }

        $transaction->load('items.category');

        return TransactionResource::make($transaction)->response();
    }

    public function destroy(Request $request, Transaction $transaction): JsonResponse
    {
        $transaction = $request->user()->transactions()->findOrFail($transaction->id);
        $transaction->delete();

        return response()->json(['message' => 'Deleted'], 200);
    }
}
