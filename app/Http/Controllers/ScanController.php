<?php

namespace App\Http\Controllers;

use App\Enums\TransactionCreatedBy;
use App\Enums\TransactionType;
use App\Http\Requests\ScanRequest;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Services\FeatureAccessService;
use App\Services\ScanService;
use App\Services\SupabaseStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ScanController extends Controller
{
    public function __construct(
        private FeatureAccessService $featureAccess,
        private SupabaseStorageService $storage,
        private ScanService $scanner,
    ) {}

    public function scan(ScanRequest $request): JsonResponse
    {
        $file = $request->file('image');
        $user = $request->user();

        $result = $this->featureAccess->canUseAiScan($user);
        if (!$result->allowed) {
            return response()->json(['message' => $result->message], 403);
        }
        $subscription = $result->subscription;

        $base64 = base64_encode($file->getContent());
        $mimeType = $file->getMimeType();

        $imgPath = $this->storage->upload($file, $user->id);

        $disabledCategoryIds = $user->disabledCategorySettings()->pluck('category_id')->all();
        $categories = Category::whereNotNull('category_id')
            ->with('parent')
            ->when(!empty($disabledCategoryIds), fn ($q) => $q->whereNotIn('id', $disabledCategoryIds))
            ->get();

        try {
            $aiResult = $this->scanner->scan($base64, $mimeType, $categories);
        } catch (\Throwable $e) {
            Log::error('AI scan failed', [
                'user_id' => $user->id,
                'img_path' => $imgPath,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'AI processing failed. The image has been saved and you can retry later.',
                'img_path' => $imgPath,
            ], 503);
        }

        if (empty($aiResult['is_receipt'])) {
            $this->storage->delete($imgPath);

            return response()->json([
                'message' => $aiResult['message'] ?? 'The image is not a valid financial document.',
            ], 422);
        }

        if (empty($aiResult['total_amount']) || empty($aiResult['date']) || empty($aiResult['items'])) {
            return response()->json([
                'message' => 'AI returned incomplete data. Please try again.',
                'img_path' => $imgPath,
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

        return response()->json($transaction, 201);
    }

    public function confirm(Request $request, Transaction $transaction): JsonResponse
    {
        $transaction = $request->user()->transactions()
            ->where('created_by', TransactionCreatedBy::Ai)
            ->whereNull('confirmed_at')
            ->findOrFail($transaction->id);

        $transaction->update(['confirmed_at' => now()]);
        $transaction->load('items.category');

        return response()->json($transaction);
    }
}
