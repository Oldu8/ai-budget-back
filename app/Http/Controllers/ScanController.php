<?php

namespace App\Http\Controllers;

use App\Http\Requests\ScanRequest;
use App\Http\Requests\UpdateReceiptTransactionRequest;
use App\Http\Resources\TransactionResource;
use App\Models\AppUser;
use App\Models\Category;
use App\Models\Transaction;
use App\Services\AiTransactionDraftService;
use App\Services\Currency\Exceptions\RateDateTooEarlyException;
use App\Services\Currency\Exceptions\UnsupportedCurrencyException;
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
        private AiTransactionDraftService $draftService,
    ) {}

    public function scan(ScanRequest $request): JsonResponse
    {
        $file = $request->file('image');
        /** @var AppUser $user */
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

        $user->loadMissing('settings');
        $currencyCode = data_get($user, 'settings.currency') ?? 'USD';

        try {
            $aiResult = $this->scanner->scan($base64, $mimeType, $categories, $currencyCode);
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
            Log::info('AI scan rejected: not a receipt', [
                'user_id' => $user->id,
                'ai_result' => $aiResult,
            ]);

            $this->storage->delete($imgPath);

            return response()->json([
                'message' => $aiResult['message'] ?? 'The image is not a valid financial document.',
            ], 422);
        }

        if (empty($aiResult['total_amount']) || empty($aiResult['items'])) {
            Log::warning('AI scan returned incomplete data', [
                'user_id' => $user->id,
                'ai_result' => $aiResult,
            ]);

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

        // Date is unreadable on some receipts; fall back to the user's local
        // today rather than rejecting an otherwise-valid scan.
        if (empty($aiResult['date'])) {
            $timezone = data_get($user, 'settings.timezone') ?? 'UTC';
            $aiResult['date'] = now($timezone)->toDateString();
        }

        try {
            $transaction = DB::transaction(function () use ($aiResult, $user, $imgPath, $subscription) {
                $transaction = $this->draftService->createExpenseDraft(
                    $user,
                    [
                        'total_amount' => $aiResult['total_amount'],
                        'date' => $aiResult['date'],
                        'currency' => $aiResult['currency'] ?? null,
                        'items' => $aiResult['items'],
                    ],
                    $aiResult,
                    $imgPath,
                );
                $subscription->increment('used_checks_count');

                return $transaction;
            });
        } catch (RateDateTooEarlyException|UnsupportedCurrencyException $e) {
            Log::warning('AI scan currency conversion failed', [
                'user_id' => $user->id,
                'currency' => $aiResult['currency'] ?? null,
                'date' => $aiResult['date'],
                'error' => $e->getMessage(),
            ]);

            try {
                $this->storage->delete($imgPath);
            } catch (\Throwable $deleteError) {
                Log::warning('Failed to delete receipt image after conversion error', [
                    'user_id' => $user->id,
                    'img_path' => $imgPath,
                    'error' => $deleteError->getMessage(),
                ]);
            }

            $message = $e instanceof RateDateTooEarlyException
                ? $e->getMessage()
                : 'This currency is not supported yet.';

            return response()->json(['message' => $message], 422);
        }

        $transaction->load('items.category');

        return TransactionResource::make($transaction)->response()->setStatusCode(201);
    }

    /**
     * Sync line items + date for the owner's transaction (draft confirm or history edit).
     */
    public function updateReceipt(UpdateReceiptTransactionRequest $request, Transaction $transaction): JsonResponse
    {
        $transaction = $request->user()->transactions()
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
