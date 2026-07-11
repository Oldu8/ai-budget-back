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
use App\Services\ErrorNotifier;
use App\Services\FeatureAccessService;
use App\Services\ReceiptTransactionService;
use App\Services\ScanService;
use App\Services\SupabaseStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use OpenAI\Exceptions\ErrorException as OpenAiErrorException;
use OpenAI\Exceptions\RateLimitException;
use OpenAI\Exceptions\ServerException;
use OpenAI\Exceptions\TransporterException;
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
        $timezone = data_get($user, 'settings.timezone') ?? 'UTC';
        $today = now($timezone)->toDateString();

        try {
            $aiResult = $this->scanner->scan($base64, $mimeType, $categories, $currencyCode, $today);
        } catch (\Throwable $e) {
            [$code, $userMessage] = $this->classifyAiFailure($e);

            Log::error('AI scan failed', [
                'user_id' => $user->id,
                'img_path' => $imgPath,
                'code' => $code,
                'model' => config('services.gemini.vision_model'),
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);

            // Infrastructure failure, not a user mistake — the team must know.
            ErrorNotifier::notify('AI receipt scan failed: '.$code, $e->getMessage(), [
                'user_id' => $user->id,
                'exception' => $e::class,
                'img_path' => $imgPath,
            ]);

            // Image kept for ops; no retry endpoint — client should upload again.
            return response()->json([
                'message' => $userMessage,
                'code' => $code,
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
                'code' => 'not_a_receipt',
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
                'code' => 'incomplete_data',
            ], 422);
        }

        // Date is unreadable on some receipts; fall back to the user's local
        // today rather than rejecting an otherwise-valid scan. A future date is
        // always a misread (string compare is safe for YYYY-MM-DD).
        if (empty($aiResult['date']) || $aiResult['date'] > $today) {
            $aiResult['date'] = $today;
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

            return response()->json([
                'message' => $message,
                'code' => $e instanceof RateDateTooEarlyException ? 'rate_date_too_early' : 'unsupported_currency',
            ], 422);
        }

        $transaction->load('items.category');

        return TransactionResource::make($transaction)->response()->setStatusCode(201);
    }

    /**
     * Map an AI-provider failure to a stable client code + safe user message.
     * All of these are infrastructure problems (never the user's fault), so the
     * caller also reports them to Discord via {@see ErrorNotifier}.
     *
     * @return array{string, string} [code, user-facing message]
     */
    private function classifyAiFailure(\Throwable $e): array
    {
        if ($e instanceof OpenAiErrorException) {
            $type = $e->getErrorType() ?? '';
            $errorCode = (string) ($e->getErrorCode() ?? '');

            if ($type === 'insufficient_quota' || $errorCode === 'insufficient_quota') {
                return ['ai_quota_exhausted', 'The AI service is out of capacity right now. We have been notified — please try again later.'];
            }

            if ($e->getStatusCode() === 429) {
                return ['ai_rate_limited', 'The AI service is overloaded. Please try again in a minute.'];
            }

            return ['ai_failed', 'AI processing failed. Please try again.'];
        }

        if ($e instanceof RateLimitException) {
            return ['ai_rate_limited', 'The AI service is overloaded. Please try again in a minute.'];
        }

        if ($e instanceof ServerException || $e instanceof TransporterException) {
            return ['ai_unavailable', 'The AI service is temporarily unavailable. Please try again later.'];
        }

        return ['ai_failed', 'AI processing failed. Please try again.'];
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
