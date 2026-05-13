<?php

declare(strict_types=1);

namespace App\Services\Voice;

use Illuminate\Support\Collection;
use OpenAI\Laravel\Facades\OpenAI;
use RuntimeException;

final class VoiceExpenseParserService
{
    public function __construct(
        private VoicePromptBuilder $promptBuilder,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function parse(string $transcript, Collection $categories, string $today, string $currencyCode): array
    {
        $response = OpenAI::chat()->create([
            'model' => 'gpt-4o-mini',
            'response_format' => ['type' => 'json_object'],
            'max_tokens' => 2048,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => $this->promptBuilder->build($categories, $today, $currencyCode),
                ],
                [
                    'role' => 'user',
                    'content' => 'Transcript (user speech converted to text):'."\n\n".$transcript,
                ],
            ],
        ]);

        $content = $response->choices[0]->message->content;
        $parsed = json_decode((string) $content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Failed to parse OpenAI response as JSON: '.json_last_error_msg());
        }

        if (! is_array($parsed)) {
            throw new RuntimeException('OpenAI response JSON was not an object.');
        }

        return $parsed;
    }
}
