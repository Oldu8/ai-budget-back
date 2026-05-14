<?php

declare(strict_types=1);

namespace App\Services\Voice;

use Illuminate\Http\UploadedFile;
use OpenAI\Laravel\Facades\OpenAI;

final class VoiceTranscriptionService
{
    /** Aligned with VoiceTransactionRequest — used only for staged temp filenames sent to OpenAI. */
    private const STAGING_EXTENSIONS = ['mp3', 'mpeg', 'mp4', 'm4a', 'wav', 'webm', 'ogg'];

    public function transcribe(UploadedFile $file): string
    {
        $model = (string) config('services.openai.audio_model', 'whisper-1');

        $sourcePath = $file->getRealPath();
        if ($sourcePath === false) {
            throw new \RuntimeException('Could not resolve uploaded file path.');
        }

        $suffix = self::stagingExtension($file);
        $stagedPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'voice_openai_upload_'.uniqid('', true).'.'.$suffix;

        if (@copy($sourcePath, $stagedPath) !== true) {
            throw new \RuntimeException('Could not stage uploaded audio for Whisper (copy failed).');
        }

        $handle = false;

        try {
            $handle = fopen($stagedPath, 'rb');
            if ($handle === false) {
                throw new \RuntimeException('Could not open staged file for Whisper upload.');
            }

            $response = OpenAI::audio()->transcribe([
                'model' => $model,
                'file' => $handle,
            ]);

            return trim($response->text);
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
            if (is_file($stagedPath)) {
                @unlink($stagedPath);
            }
        }
    }

    private static function stagingExtension(UploadedFile $file): string
    {
        $original = basename((string) $file->getClientOriginalName());
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));

        if ($ext === '') {
            $ext = strtolower((string) ($file->guessExtension() ?? ''));
        }

        if (in_array($ext, self::STAGING_EXTENSIONS, true)) {
            return $ext;
        }

        return 'm4a';
    }
}
