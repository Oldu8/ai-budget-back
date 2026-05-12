<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\VoiceTransactionRequest;
use App\Http\Resources\TransactionResource;
use App\Models\AppUser;
use App\Models\Category;
use App\Services\AiTransactionDraftService;
use App\Services\FeatureAccessService;
use App\Services\Voice\VoiceExpenseParserService;
use App\Services\Voice\VoiceTranscriptionService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class VoiceTransactionController extends Controller
{
    public function __construct(
        private FeatureAccessService $featureAccess,
        private VoiceTranscriptionService $transcription,
        private VoiceExpenseParserService $parser,
        private AiTransactionDraftService $draftService,
    ) {}

    public function store(VoiceTransactionRequest $request): JsonResponse
    {
        $file = $request->file('audio');
        /** @var AppUser $user */
        $user = $request->user();

        $access = $this->featureAccess->canAccess($user, 'voice_expense');
        if (! $access->allowed) {
            return response()->json(['message' => $access->message], 403);
        }
        $subscription = $access->subscription;

        $categories = Category::leafCategoriesForUser($user);

        $user->loadMissing('settings');

        // Prefer data_get when settings row may be absent (HasOne yields null).
        $timezone = data_get($user, 'settings.timezone') ?? config('app.timezone', 'UTC');
        $today = Carbon::now($timezone)->format('Y-m-d');
        $currencyCode = data_get($user, 'settings.currency') ?? 'USD';

        try {
            $transcript = $this->transcription->transcribe($file);
        } catch (\Throwable $e) {
            Log::error('Voice expense transcription failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'AI transcription failed. Please try again.',
            ], 503);
        }

        try {
            $aiResult = $this->parser->parse($transcript, $categories, $today, $currencyCode);
        } catch (\Throwable $e) {
            Log::error('Voice expense parse failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'AI processing failed. Please try again.',
            ], 503);
        }

        if (empty($aiResult['is_expense'])) {
            return response()->json([
                'message' => 'The recording does not appear to contain an expense.',
            ], 422);
        }

        if (empty($aiResult['total_amount']) || empty($aiResult['date']) || empty($aiResult['items'])) {
            return response()->json([
                'message' => 'AI returned incomplete data. Please try again.',
            ], 422);
        }

        $transaction = DB::transaction(function () use ($user, $aiResult, $transcript, $subscription) {
            $tx = $this->draftService->createExpenseDraft(
                $user,
                [
                    'total_amount' => $aiResult['total_amount'],
                    'date' => $aiResult['date'],
                    'items' => $aiResult['items'],
                ],
                [
                    'source' => 'voice_expense',
                    'transcript' => $transcript,
                    'parsed' => $aiResult,
                ],
                null,
            );
            $subscription->increment('used_checks_count');

            return $tx;
        });

        $transaction->load('items.category');

        return TransactionResource::make($transaction)->response()->setStatusCode(201);
    }
}
