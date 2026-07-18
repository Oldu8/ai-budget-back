<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PlanType;
use App\Enums\TransactionCreatedBy;
use App\Enums\TransactionType;
use App\Models\Transaction;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use OpenAI\Exceptions\ErrorException as OpenAiErrorException;
use OpenAI\Exceptions\RateLimitException;
use OpenAI\Exceptions\ServerException as OpenAiServerException;
use OpenAI\Exceptions\TransporterException;
use OpenAI\Responses\Chat\CreateResponse;
use OpenAI\Testing\ClientFake;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Client\ClientExceptionInterface;
use Tests\TestCase;

final class ScanControllerTest extends TestCase
{
    use RefreshDatabase;

    private string $supabaseUrl;

    protected function setUp(): void
    {
        parent::setUp();
        $this->supabaseUrl = rtrim(config('services.supabase.url'), '/');
    }

    /**
     * Receipt scanning uses the dedicated `openai.scan` client (Gemini),
     * not the global OpenAI facade — fake that binding specifically.
     *
     * @param  array<int, CreateResponse>  $responses
     */
    private function fakeScanAi(array $responses): void
    {
        $this->app->instance('openai.scan', new ClientFake($responses));
    }

    // -------------------------------------------------------------------------
    // scan
    // -------------------------------------------------------------------------

    public function test_scan_returns_403_when_feature_not_available(): void
    {
        $user = $this->makeAppUser();
        // No subscription at all
        $response = $this->apiAs($user)->post('/api/transactions/scan', [
            'image' => UploadedFile::fake()->create('receipt.jpg', 100, 'image/jpeg'),
        ]);

        $response->assertForbidden();
    }

    public function test_scan_returns_503_when_ai_throws_and_does_not_delete_image(): void
    {
        $user = $this->makeAppUser();
        $this->subscribe($user, $this->makePlan(PlanType::Free, 10));
        $bucket = config('services.supabase.storage_bucket');

        $uploaded = false;
        $deleted = false;

        Http::fake(function ($request) use (&$uploaded, &$deleted, $bucket) {
            if (str_contains($request->url(), "/storage/v1/object/{$bucket}/")) {
                $uploaded = true;

                return Http::response(['Key' => 'user/receipt.jpg'], 200);
            }
            if (str_contains($request->url(), "/storage/v1/object/{$bucket}")) {
                $deleted = true;

                return Http::response([], 200);
            }

            return Http::response([], 200);
        });

        $this->fakeScanAi([
            CreateResponse::fake(['choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => 'invalid json {{{', 'function_call' => null, 'tool_calls' => []],
                'logprobs' => null,
                'finish_reason' => 'stop',
            ]]]),
        ]);

        $response = $this->apiAs($user)->post('/api/transactions/scan', [
            'image' => UploadedFile::fake()->create('receipt.jpg', 100, 'image/jpeg'),
        ]);

        $response->assertStatus(503);
        $this->assertTrue($uploaded);
        $this->assertFalse($deleted);
    }

    #[DataProvider('aiFailureProvider')]
    public function test_scan_classifies_ai_provider_failures(\Throwable $exception, string $expectedCode): void
    {
        $user = $this->makeAppUser();
        $this->subscribe($user, $this->makePlan(PlanType::Free, 10));

        Http::fake(fn () => Http::response(['Key' => 'user/receipt.jpg'], 200));
        $this->fakeScanAi([$exception]);

        $this->apiAs($user)->post('/api/transactions/scan', [
            'image' => UploadedFile::fake()->create('receipt.jpg', 100, 'image/jpeg'),
        ])->assertStatus(503)->assertJsonPath('code', $expectedCode);
    }

    /** @return array<string, array{0: \Throwable, 1: string}> */
    public static function aiFailureProvider(): array
    {
        $response = fn (int $status) => new Psr7Response($status);

        return [
            'insufficient quota (type)' => [
                new OpenAiErrorException(['message' => 'no quota', 'type' => 'insufficient_quota'], $response(429)),
                'ai_quota_exhausted',
            ],
            'insufficient quota (code)' => [
                new OpenAiErrorException(['message' => 'no quota', 'code' => 'insufficient_quota'], $response(429)),
                'ai_quota_exhausted',
            ],
            'rate limited via error status' => [
                new OpenAiErrorException(['message' => 'slow down'], $response(429)),
                'ai_rate_limited',
            ],
            'generic API error' => [
                new OpenAiErrorException(['message' => 'weird error'], $response(400)),
                'ai_failed',
            ],
            'rate limit exception' => [
                new RateLimitException($response(429)),
                'ai_rate_limited',
            ],
            'server exception' => [
                new OpenAiServerException($response(500)),
                'ai_unavailable',
            ],
            'transporter exception' => [
                new TransporterException(new class extends \Exception implements ClientExceptionInterface {}),
                'ai_unavailable',
            ],
        ];
    }

    public function test_scan_returns_422_and_deletes_image_when_not_receipt(): void
    {
        $user = $this->makeAppUser();
        $this->subscribe($user, $this->makePlan(PlanType::Free, 10));
        $bucket = config('services.supabase.storage_bucket');

        $deleted = false;

        Http::fake(function ($request) use (&$deleted, $bucket) {
            if (str_contains($request->url(), "/storage/v1/object/{$bucket}")) {
                if ($request->method() === 'DELETE' || str_contains($request->url(), 'DELETE')) {
                    $deleted = true;
                }

                return Http::response(['Key' => 'path'], 200);
            }

            return Http::response([], 200);
        });

        $this->fakeScanAi([
            CreateResponse::fake(['choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => json_encode(['is_receipt' => false, 'message' => 'Not a receipt']), 'function_call' => null, 'tool_calls' => []],
                'logprobs' => null,
                'finish_reason' => 'stop',
            ]]]),
        ]);

        $response = $this->apiAs($user)->post('/api/transactions/scan', [
            'image' => UploadedFile::fake()->create('photo.jpg', 100, 'image/jpeg'),
        ]);

        $response->assertUnprocessable()
            ->assertJsonFragment(['message' => 'Not a receipt']);
    }

    public function test_scan_returns_422_and_deletes_image_when_ai_returns_incomplete_data(): void
    {
        $user = $this->makeAppUser();
        $this->subscribe($user, $this->makePlan(PlanType::Free, 10));

        Http::fake(fn () => Http::response(['Key' => 'path'], 200));

        $this->fakeScanAi([
            CreateResponse::fake(['choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => json_encode(['is_receipt' => true]), 'function_call' => null, 'tool_calls' => []],
                'logprobs' => null,
                'finish_reason' => 'stop',
            ]]]),
        ]);

        $this->apiAs($user)->post('/api/transactions/scan', [
            'image' => UploadedFile::fake()->create('receipt.jpg', 100, 'image/jpeg'),
        ])->assertUnprocessable()
            ->assertJsonFragment(['message' => 'AI returned incomplete data. Please try another photo.']);
    }

    public function test_scan_returns_422_when_image_delete_fails_after_incomplete_data(): void
    {
        $user = $this->makeAppUser();
        $this->subscribe($user, $this->makePlan(PlanType::Free, 10));

        Http::fake(fn ($request) => $request->method() === 'DELETE'
            ? Http::response([], 500)
            : Http::response(['Key' => 'path'], 200));

        $this->fakeScanAi([
            CreateResponse::fake(['choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => json_encode(['is_receipt' => true]), 'function_call' => null, 'tool_calls' => []],
                'logprobs' => null,
                'finish_reason' => 'stop',
            ]]]),
        ]);

        // Delete failure is logged and swallowed — the response is unaffected.
        $this->apiAs($user)->post('/api/transactions/scan', [
            'image' => UploadedFile::fake()->create('receipt.jpg', 100, 'image/jpeg'),
        ])->assertUnprocessable()
            ->assertJsonFragment(['message' => 'AI returned incomplete data. Please try another photo.']);
    }

    public function test_scan_returns_422_when_rate_date_is_too_early(): void
    {
        $user = $this->makeAppUser();
        $this->makeSettings($user, currency: 'USD');
        $leaf = $this->makeCategory();
        $this->subscribe($user, $this->makePlan(PlanType::Free, 10));

        Http::fake(fn ($request) => $request->method() === 'DELETE'
            ? Http::response([], 500) // delete failure here must also be swallowed
            : Http::response(['Key' => 'path'], 200));

        $aiJson = json_encode([
            'is_receipt' => true,
            'date' => '2020-01-01', // well before config('rates.earliest_date')
            'currency' => 'USD',
            'total_amount' => 1000,
            'items' => [['product' => 'Old', 'amount' => 1000, 'category_id' => $leaf->id, 'category_label' => $leaf->label]],
        ]);
        $this->fakeScanAi([
            CreateResponse::fake(['choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => $aiJson, 'function_call' => null, 'tool_calls' => []],
                'logprobs' => null,
                'finish_reason' => 'stop',
            ]]]),
        ]);

        $this->apiAs($user)->post('/api/transactions/scan', [
            'image' => UploadedFile::fake()->create('receipt.jpg', 100, 'image/jpeg'),
        ])->assertUnprocessable()->assertJsonPath('code', 'rate_date_too_early');
    }

    public function test_scan_returns_422_when_currency_is_unsupported(): void
    {
        $user = $this->makeAppUser();
        $this->makeSettings($user, currency: 'ZZZ'); // not in config('rates.supported')
        $leaf = $this->makeCategory();
        $this->subscribe($user, $this->makePlan(PlanType::Free, 10));

        Http::fake(fn () => Http::response(['Key' => 'path'], 200));

        $aiJson = json_encode([
            'is_receipt' => true,
            'date' => now()->toDateString(),
            'currency' => 'USD',
            'total_amount' => 1000,
            'items' => [['product' => 'Thing', 'amount' => 1000, 'category_id' => $leaf->id, 'category_label' => $leaf->label]],
        ]);
        $this->fakeScanAi([
            CreateResponse::fake(['choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => $aiJson, 'function_call' => null, 'tool_calls' => []],
                'logprobs' => null,
                'finish_reason' => 'stop',
            ]]]),
        ]);

        $this->apiAs($user)->post('/api/transactions/scan', [
            'image' => UploadedFile::fake()->create('receipt.jpg', 100, 'image/jpeg'),
        ])->assertUnprocessable()->assertJsonPath('code', 'unsupported_currency');
    }

    public function test_scan_creates_draft_and_increments_quota_on_success(): void
    {
        $user = $this->makeAppUser();
        $leaf = $this->makeCategory();
        $this->subscribe($user, $this->makePlan(PlanType::Free, 10), usedChecks: 2);

        Http::fake(fn () => Http::response(['Key' => 'user/receipt.jpg'], 200));

        $aiJson = json_encode([
            'is_receipt' => true,
            'date' => '2026-05-01',
            'total_amount' => 2500,
            'items' => [[
                'product' => 'Coffee',
                'amount' => 2500,
                'category_id' => $leaf->id,
                'category_label' => $leaf->label,
            ]],
        ]);

        $this->fakeScanAi([
            CreateResponse::fake(['choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => $aiJson, 'function_call' => null, 'tool_calls' => []],
                'logprobs' => null,
                'finish_reason' => 'stop',
            ]]]),
        ]);

        $response = $this->apiAs($user)->post('/api/transactions/scan', [
            'image' => UploadedFile::fake()->create('receipt.jpg', 100, 'image/jpeg'),
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.type', 'expense')
            ->assertJsonPath('data.created_by', 'ai')
            ->assertJsonPath('data.confirmed_at', null)
            ->assertJsonPath('data.total_amount', 2500);

        $sub = $user->userSubscriptions()->first();
        $this->assertSame(3, $sub->fresh()->used_checks_count);
    }

    public function test_scan_falls_back_to_users_timezone_date_when_ai_omits_date(): void
    {
        $user = $this->makeAppUser();
        $this->makeSettings($user, currency: 'USD', timezone: 'Pacific/Kiritimati');
        $leaf = $this->makeCategory();
        $this->subscribe($user, $this->makePlan(PlanType::Free, 10));

        Http::fake(fn () => Http::response(['Key' => 'user/receipt.jpg'], 200));

        $aiJson = json_encode([
            'is_receipt' => true,
            'message' => null,
            'date' => null,
            'currency' => 'USD',
            'total_amount' => 1500,
            'items' => [[
                'product' => 'Snack',
                'amount' => 1500,
                'category_id' => $leaf->id,
                'category_label' => $leaf->label,
            ]],
        ]);

        $this->fakeScanAi([
            CreateResponse::fake(['choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => $aiJson, 'function_call' => null, 'tool_calls' => []],
                'logprobs' => null,
                'finish_reason' => 'stop',
            ]]]),
        ]);

        $response = $this->apiAs($user)->post('/api/transactions/scan', [
            'image' => UploadedFile::fake()->create('receipt.jpg', 100, 'image/jpeg'),
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.date', now('Pacific/Kiritimati')->toDateString());
    }

    // -------------------------------------------------------------------------
    // updateReceipt
    // -------------------------------------------------------------------------

    public function test_update_receipt_returns_404_for_other_users_transaction(): void
    {
        $user = $this->makeAppUser();
        $other = $this->makeAppUser();
        $leaf = $this->makeCategory();

        $tx = Transaction::create([
            'app_user_id' => $other->id,
            'total_amount' => 1000,
            'date' => '2026-05-01',
            'type' => TransactionType::Expense,
            'created_by' => TransactionCreatedBy::Ai,
            'confirmed_at' => null,
        ]);

        $this->apiAs($user)->putJson("/api/transactions/{$tx->id}/update", [
            'date' => '2026-05-01',
            'items' => [['amount' => 1000, 'category_id' => $leaf->id]],
        ])->assertNotFound();
    }

    public function test_update_receipt_confirms_draft_transaction(): void
    {
        $user = $this->makeAppUser();
        $leaf = $this->makeCategory();

        $tx = Transaction::create([
            'app_user_id' => $user->id,
            'total_amount' => 1000,
            'date' => '2026-05-01',
            'type' => TransactionType::Expense,
            'created_by' => TransactionCreatedBy::Ai,
            'confirmed_at' => null,
            'img_path' => 'user/receipt.jpg',
        ]);

        $this->apiAs($user)->putJson("/api/transactions/{$tx->id}/update", [
            'date' => '2026-05-01',
            'items' => [['amount' => 1000, 'category_id' => $leaf->id]],
        ])->assertOk()
            ->assertJsonPath('data.confirmed_at', fn ($v) => $v !== null);

        $this->assertNotNull($tx->fresh()->confirmed_at);
    }

    // -------------------------------------------------------------------------
    // image
    // -------------------------------------------------------------------------

    public function test_image_returns_404_for_other_users_transaction(): void
    {
        $user = $this->makeAppUser();
        $other = $this->makeAppUser();

        $tx = Transaction::create([
            'app_user_id' => $other->id,
            'total_amount' => 1000,
            'date' => '2026-05-01',
            'type' => TransactionType::Expense,
            'created_by' => TransactionCreatedBy::Ai,
            'confirmed_at' => now(),
            'img_path' => 'other/receipt.jpg',
        ]);

        $this->apiAs($user)->getJson("/api/transactions/{$tx->id}/image")->assertNotFound();
    }

    public function test_image_returns_404_when_img_path_is_null(): void
    {
        $user = $this->makeAppUser();

        $tx = Transaction::create([
            'app_user_id' => $user->id,
            'total_amount' => 1000,
            'date' => '2026-05-01',
            'type' => TransactionType::Expense,
            'created_by' => TransactionCreatedBy::Ai,
            'confirmed_at' => now(),
            'img_path' => null,
        ]);

        $this->apiAs($user)->getJson("/api/transactions/{$tx->id}/image")->assertNotFound();
    }

    public function test_image_returns_signed_url(): void
    {
        $user = $this->makeAppUser();
        $supabaseUrl = rtrim(config('services.supabase.url'), '/');
        $bucket = config('services.supabase.storage_bucket');

        $tx = Transaction::create([
            'app_user_id' => $user->id,
            'total_amount' => 1000,
            'date' => '2026-05-01',
            'type' => TransactionType::Expense,
            'created_by' => TransactionCreatedBy::Ai,
            'confirmed_at' => now(),
            'img_path' => 'user/receipt.jpg',
        ]);

        Http::fake([
            $supabaseUrl."/storage/v1/object/sign/{$bucket}/*" => Http::response([
                'signedURL' => '/storage/v1/object/sign/receipts/user/receipt.jpg?token=abc',
            ], 200),
        ]);

        $this->apiAs($user)->getJson("/api/transactions/{$tx->id}/image")
            ->assertOk()
            ->assertJsonStructure(['url']);
    }
}
