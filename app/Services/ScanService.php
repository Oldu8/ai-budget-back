<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Scan\ScanPromptBuilder;
use Illuminate\Support\Collection;
use OpenAI\Laravel\Facades\OpenAI;
use RuntimeException;

final class ScanService
{
    public function __construct(
        private ScanPromptBuilder $promptBuilder,
    ) {}

    public function scan(string $base64Image, string $mimeType, Collection $categories): array
    {
        $response = OpenAI::chat()->create([
            'model' => 'gpt-4o-mini',
            'response_format' => ['type' => 'json_object'],
            'max_tokens' => 2048,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => $this->promptBuilder->build($categories),
                ],
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'image_url',
                            'image_url' => [
                                'url' => "data:{$mimeType};base64,{$base64Image}",
                            ],
                        ],
                        [
                            'type' => 'text',
                            'text' => 'Analyze this image and return a structured JSON response.',
                        ],
                    ],
                ],
            ],
        ]);

        $content = $response->choices[0]->message->content;
        $parsed = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Failed to parse OpenAI response as JSON: '.json_last_error_msg());
        }

        return $parsed;
    }
}
