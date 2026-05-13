<?php

declare(strict_types=1);

namespace App\Services\Voice;

use Illuminate\Http\UploadedFile;
use OpenAI\Laravel\Facades\OpenAI;

final class VoiceTranscriptionService
{
    public function transcribe(UploadedFile $file): string
    {
        $model = config('services.openai.audio_model', 'whisper-1');
        $path = $file->getRealPath();
        if ($path === false) {
            throw new \RuntimeException('Could not resolve uploaded file path.');
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Could not open uploaded file for reading.');
        }

        try {
            $response = OpenAI::audio()->transcribe([
                'model' => $model,
                'file' => $handle,
            ]);
        } finally {
            fclose($handle);
        }

        return trim($response->text);
    }
}
