<?php

namespace App\Http\Controllers;

use App\Enums\TransactionCreatedBy;
use App\Enums\TransactionType;
use App\Http\Requests\ScanRequest;
use App\Http\Requests\UpdateReceiptTransactionRequest;
use App\Http\Resources\TransactionResource;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Services\FeatureAccessService;
use App\Services\ReceiptTransactionService;
use App\Services\ScanService;
use App\Services\SupabaseStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ScanController extends Controller
{
    public function __construct(
        private FeatureAccessService $featureAccess,
        private SupabaseStorageService $storage,
        private ScanService $scanner,
        private ReceiptTransactionService $receiptTransactionService,
    ) {}

    public function scan(ScanRequest $request): JsonResponse
    {
        $file = $request->file('image');
        $user = $request->user();

        $result = $this->featureAccess->canUseAiScan($user);
        if (! $result->allowed) {
            return response()->json(['message' => $result->message], 403);
        }
        $subscription = $result->subscription;

        $base64 = base64_encode($file->getContent());
        $mimeType = $file->getMimeType();

        $imgPath = $this->storage->upload($file, $user->id);

        $categories = Category::leafCategoriesForUser($user);

        try {
            $aiResult = $this->scanner->scan($base64, $mimeType, $categories);
        } catch (\Throwable $e) {
            Log::error('AI scan failed', [
                'user_id' => $user->id,
                'img_path' => $imgPath,
                'error' => $e->getMessage(),
            ]);

            // Image kept for ops; no retry endpoint — client should upload again.
            return response()->json([
                'message' => 'AI processing failed. Please try again.',
            ], 503);
        }

        if (empty($aiResult['is_receipt'])) {
            $this->storage->delete($imgPath);

            return response()->json([
                'message' => $aiResult['message'] ?? 'The image is not a valid financial document.',
            ], 422);
        }

        if (empty($aiResult['total_amount']) || empty($aiResult['date']) || empty($aiResult['items'])) {
            try {
                $this->storage->delete($imgPath);
            } catch (\Throwable $e) {
                Log::warning('Failed to delete receipt image after incomplete AI response', [
                    'user_id' => $user->id,
                    'img_path' => $imgPath,
                    'error' => $e->getMessage(),
                ]);
            }

            return response()->json([
                'message' => 'AI returned incomplete data. Please try another photo.',
            ], 422);
        }

        $validCategoryIds = $categories->pluck('id')->toArray();
        $fallbackCategoryId = $categories->firstWhere('name', 'finance_other_misc')?->id;

        $transaction = DB::transaction(function () use ($aiResult, $user, $imgPath, $validCategoryIds, $fallbackCategoryId, $subscription) {
            $transaction = Transaction::create([
                'app_user_id' => $user->id,
                'total_amount' => (int) $aiResult['total_amount'],
                'date' => $aiResult['date'],
                'type' => TransactionType::Expense,
                'created_by' => TransactionCreatedBy::Ai,
                'confirmed_at' => null,
                'img_path' => $imgPath,
                'raw_ai_data' => $aiResult,
            ]);

            // TODO: Может явно выдавать ошибку, если нет категории, а не фаллбек делать
            foreach ($aiResult['items'] as $item) {
                $categoryId = in_array((int) ($item['category_id'] ?? 0), $validCategoryIds)
                    ? (int) $item['category_id']
                    : $fallbackCategoryId;

                TransactionItem::create([
                    'transaction_id' => $transaction->id,
                    'category_id' => $categoryId,
                    'amount' => (int) ($item['amount'] ?? 0),
                    'label' => $item['product'] ?? null,
                ]);
            }

            $subscription->increment('used_checks_count');

            return $transaction;
        });

        $transaction->load('items.category');

        return TransactionResource::make($transaction)->response()->setStatusCode(201);
    }

    /**
     * Save edited receipt lines and confirm in one request (atomic).
     */
    public function updateReceipt(UpdateReceiptTransactionRequest $request, Transaction $transaction): JsonResponse
    {
        $transaction = $request->user()->transactions()
            ->where('created_by', TransactionCreatedBy::Ai)
            ->whereNull('confirmed_at')
            ->findOrFail($transaction->id);

        $updated = $this->receiptTransactionService->saveAndConfirm($transaction, $request->validated());

        return TransactionResource::make($updated)->response();
    }

    /**
     * Signed URL for private bucket receipt preview (Supabase Storage REST sign endpoint).
     */
    public function image(Request $request, Transaction $transaction): JsonResponse
    {
        $transaction = $request->user()->transactions()->findOrFail($transaction->id);

        if (! $transaction->img_path) {
            throw new NotFoundHttpException;
        }

        $payload = $this->storage->createSignedUrl($transaction->img_path, 300);

        return response()->json($payload);
    }
}
