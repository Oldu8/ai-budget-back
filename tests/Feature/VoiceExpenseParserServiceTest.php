<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Voice\VoiceExpenseParserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Chat\CreateResponse;
use RuntimeException;
use Tests\TestCase;

final class VoiceExpenseParserServiceTest extends TestCase
{
    use RefreshDatabase;

    private function fakeChatResponse(string $content): CreateResponse
    {
        return CreateResponse::fake(['choices' => [[
            'index' => 0,
            'message' => ['role' => 'assistant', 'content' => $content, 'function_call' => null, 'tool_calls' => []],
            'logprobs' => null,
            'finish_reason' => 'stop',
        ]]]);
    }

    public function test_parses_valid_json_object_response(): void
    {
        OpenAI::fake([$this->fakeChatResponse(json_encode(['is_expense' => true, 'total_amount' => 500]))]);

        $result = app(VoiceExpenseParserService::class)->parse('coffee', new Collection, '2026-01-01', 'USD');

        $this->assertTrue($result['is_expense']);
    }

    public function test_throws_when_response_is_not_valid_json(): void
    {
        OpenAI::fake([$this->fakeChatResponse('not valid json {')]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Failed to parse OpenAI response as JSON/');

        app(VoiceExpenseParserService::class)->parse('coffee', new Collection, '2026-01-01', 'USD');
    }

    public function test_throws_when_response_json_is_not_an_object(): void
    {
        OpenAI::fake([$this->fakeChatResponse('42')]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('OpenAI response JSON was not an object.');

        app(VoiceExpenseParserService::class)->parse('coffee', new Collection, '2026-01-01', 'USD');
    }
}
