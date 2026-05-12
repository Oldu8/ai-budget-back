<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PlanType;
use App\Models\Transaction;
use App\Models\UserSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Audio\TranscriptionResponse;
use OpenAI\Responses\Chat\CreateResponse;
use Tests\TestCase;

final class VoiceTransactionControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_premium_voice_expense_returns_403(): void
    {
        $user = $this->makeAppUser();
        $this->subscribe($user, $this->makePlan(PlanType::Plus));

        $response = $this->apiAs($user)->post('/api/transactions/voice', [
            'audio' => UploadedFile::fake()->create('note.mp3', 100, 'audio/mpeg'),
        ]);

        $response->assertForbidden()
            ->assertJsonFragment(['message' => 'Voice expense entry is only available on Premium.']);
    }

    public function test_premium_voice_expense_returns_403_when_scan_quota_exhausted(): void
    {
        $user = $this->makeAppUser();
        $this->subscribe($user, $this->makePlan(PlanType::Premium, checkLimit: 10), usedChecks: 10);

        $response = $this->apiAs($user)->post('/api/transactions/voice', [
            'audio' => UploadedFile::fake()->create('note.mp3', 100, 'audio/mpeg'),
        ]);

        $response->assertForbidden()
            ->assertJsonFragment(['message' => 'Scan limit reached for this billing period.']);
    }

    public function test_premium_voice_expense_creates_draft_and_increments_scan_quota(): void
    {
        $leaf = $this->makeCategory();
        $parsedJson = json_encode([
            'is_expense' => true,
            'date' => '2026-05-01',
            'total_amount' => 500,
            'items' => [[
                'product' => 'Coffee',
                'amount' => 500,
                'category_id' => $leaf->id,
                'category_label' => 'Subscriptions',
            ]],
        ], JSON_THROW_ON_ERROR);

        OpenAI::fake([
            TranscriptionResponse::fake(['text' => 'coffee five euros today']),
            CreateResponse::fake([
                'choices' => [[
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => $parsedJson,
                        'function_call' => null,
                        'tool_calls' => [],
                    ],
                    'logprobs' => null,
                    'finish_reason' => 'stop',
                ]],
            ]),
        ]);

        $user = $this->makeAppUser();
        $this->makeSettings($user, 'EUR', 'en', 'Europe/Berlin');
        $this->subscribe($user, $this->makePlan(PlanType::Premium, 50), usedChecks: 7);

        $response = $this->apiAs($user)->post('/api/transactions/voice', [
            'audio' => UploadedFile::fake()->create('note.mp3', 200, 'audio/mpeg'),
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.type', 'expense')
            ->assertJsonPath('data.created_by', 'ai')
            ->assertJsonPath('data.confirmed_at', null)
            ->assertJsonPath('data.total_amount', 500)
            ->assertJsonPath('data.raw_ai_data.source', 'voice_expense');

        $sub = UserSubscription::query()->where('app_user_id', $user->id)->first();
        $this->assertNotNull($sub);
        $this->assertSame(8, $sub->fresh()->used_checks_count);

        $tid = $response->json('data.id');
        $this->assertNotNull($tid);
        $tx = Transaction::query()->find($tid);
        $this->assertNotNull($tx);
        $this->assertSame('voice_expense', $tx->raw_ai_data['source'] ?? null);
    }

    public function test_voice_expense_returns_422_when_ai_says_not_an_expense(): void
    {
        $parsedJson = json_encode([
            'is_expense' => false,
            'message' => 'No expense detected',
        ], JSON_THROW_ON_ERROR);

        OpenAI::fake([
            TranscriptionResponse::fake(['text' => 'the weather is nice today']),
            CreateResponse::fake([
                'choices' => [[
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => $parsedJson,
                        'function_call' => null,
                        'tool_calls' => [],
                    ],
                    'logprobs' => null,
                    'finish_reason' => 'stop',
                ]],
            ]),
        ]);

        $user = $this->makeAppUser();
        $this->subscribe($user, $this->makePlan(PlanType::Premium, 50));

        $response = $this->apiAs($user)->post('/api/transactions/voice', [
            'audio' => UploadedFile::fake()->create('note.mp3', 100, 'audio/mpeg'),
        ]);

        $response->assertUnprocessable()
            ->assertJsonFragment(['message' => 'The recording does not appear to contain an expense.']);
    }

    public function test_voice_expense_returns_422_when_ai_returns_incomplete_data(): void
    {
        $parsedJson = json_encode([
            'is_expense' => true,
        ], JSON_THROW_ON_ERROR);

        OpenAI::fake([
            TranscriptionResponse::fake(['text' => 'I spent something somewhere']),
            CreateResponse::fake([
                'choices' => [[
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => $parsedJson,
                        'function_call' => null,
                        'tool_calls' => [],
                    ],
                    'logprobs' => null,
                    'finish_reason' => 'stop',
                ]],
            ]),
        ]);

        $user = $this->makeAppUser();
        $this->subscribe($user, $this->makePlan(PlanType::Premium, 50));

        $response = $this->apiAs($user)->post('/api/transactions/voice', [
            'audio' => UploadedFile::fake()->create('note.mp3', 100, 'audio/mpeg'),
        ]);

        $response->assertUnprocessable()
            ->assertJsonFragment(['message' => 'AI returned incomplete data. Please try again.']);
    }
}
