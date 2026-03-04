<?php

namespace App\Http\Controllers;

use App\Enums\TransactionCreatedBy;
use App\Http\Requests\StoreTransactionRequest;
use App\Http\Requests\UpdateTransactionRequest;
use App\Models\Transaction;
use App\Models\TransactionItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Manual transaction CRUD.
 * For AI-created transactions see future AiReceiptController / POST /api/transactions/from-receipt.
 */
class ManualTransactionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = Transaction::where('app_user_id', $user->id)
            ->with('items.category')
            ->orderByDesc('date')
            ->orderByDesc('created_at');

        if ($request->has('from')) {
            $query->where('date', '>=', $request->input('from'));
        }
        if ($request->has('to')) {
            $query->where('date', '<=', $request->input('to'));
        }
        if ($request->has('type')) {
            $query->where('type', $request->input('type'));
        }

        $transactions = $query->paginate($request->input('per_page', 20));

        return response()->json($transactions);
    }

    public function store(StoreTransactionRequest $request): JsonResponse
    {
        $totalAmount = $request->validated('total_amount');
        $transaction = Transaction::create([
            'app_user_id' => $request->user()->id,
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
        ]);

        $transaction->load('items.category');

        return response()->json($transaction, 201);
    }

    public function show(Request $request, Transaction $transaction): JsonResponse
    {
        if ($transaction->app_user_id !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $transaction->load('items.category');

        return response()->json($transaction);
    }

    public function update(UpdateTransactionRequest $request, Transaction $transaction): JsonResponse
    {
        if ($transaction->app_user_id !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

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

        return response()->json($transaction);
    }

    public function destroy(Request $request, Transaction $transaction): JsonResponse
    {
        if ($transaction->app_user_id !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $transaction->delete();

        return response()->json(['message' => 'Deleted'], 200);
    }
}
