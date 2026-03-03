<?php

namespace App\Http\Controllers;

use App\Enums\TransactionCreatedBy;
use App\Http\Requests\StoreTransactionRequest;
use App\Http\Requests\UpdateTransactionRequest;
use App\Models\Transaction;
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
            ->with('category')
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
        $transaction = Transaction::create([
            'app_user_id' => $request->user()->id,
            'amount' => $request->validated('amount'),
            'date' => $request->validated('date'),
            'type' => $request->validated('type'),
            'category_id' => $request->validated('category_id'),
            'created_by' => TransactionCreatedBy::User,
            'confirmed_at' => now(),
        ]);

        $transaction->load('category');

        return response()->json($transaction, 201);
    }

    public function show(Request $request, Transaction $transaction): JsonResponse
    {
        if ($transaction->app_user_id !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $transaction->load('category');

        return response()->json($transaction);
    }

    public function update(UpdateTransactionRequest $request, Transaction $transaction): JsonResponse
    {
        if ($transaction->app_user_id !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $transaction->update($request->validated());
        $transaction->load('category');

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
