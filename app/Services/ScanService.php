<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Scan\ScanPromptBuilder;
use App\Services\Scan\ScanResponseSchemaBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use OpenAI\Contracts\ClientContract;
use RuntimeException;

final class ScanService
{
    public function __construct(
        private ScanPromptBuilder $promptBuilder,
        private ScanResponseSchemaBuilder $responseSchemaBuilder,
    ) {}

    public function scan(string $base64Image, string $mimeType, Collection $categories, string $currencyCode, string $today): array
    {
        $model = (string) config('services.gemini.vision_model');

        $params = [
            'model' => $model,
            'response_format' => $this->responseSchemaBuilder->buildResponseFormat(),
            // Replaces max_tokens (rejected by reasoning models). Generous cap:
            // hidden reasoning tokens count against this budget too.
            'max_completion_tokens' => 16384,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => $this->promptBuilder->build($categories, $currencyCode, $today),
                ],
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'image_url',
                            'image_url' => [
                                'url' => "data:{$mimeType};base64,{$base64Image}",
                                'detail' => 'high',
                            ],
                        ],
                        [
                            'type' => 'text',
                            'text' => 'Analyze this image.',
                        ],
                    ],
                ],
            ],
        ];

        /** @var ClientContract $client */
        $client = app('openai.scan');

        $response = $client->chat()->create($params);

        Log::info('Receipt scan token usage', [
            'model' => $model,
            'prompt_tokens' => $response->usage->promptTokens ?? null,
            'completion_tokens' => $response->usage->completionTokens ?? null,
            'total_tokens' => $response->usage->totalTokens ?? null,
        ]);

        $content = $response->choices[0]->message->content;
        $parsed = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Failed to parse OpenAI response as JSON: '.json_last_error_msg());
        }

        return $parsed;
    }
}
