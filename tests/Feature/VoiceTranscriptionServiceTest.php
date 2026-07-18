<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Voice\VoiceTranscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Mockery;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Audio\TranscriptionResponse;
use RuntimeException;
use Tests\TestCase;

final class VoiceTranscriptionServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): VoiceTranscriptionService
    {
        return app(VoiceTranscriptionService::class);
    }

    public function test_throws_when_uploaded_file_real_path_is_unavailable(): void
    {
        $file = Mockery::mock(UploadedFile::class);
        $file->shouldReceive('getRealPath')->andReturn(false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Could not resolve uploaded file path.');

        $this->service()->transcribe($file);
    }

    public function test_transcribes_using_the_files_own_extension(): void
    {
        OpenAI::fake([TranscriptionResponse::fake(['text' => '  hello world  '])]);
        $file = UploadedFile::fake()->create('note.mp3', 10, 'audio/mpeg');

        $this->assertSame('hello world', $this->service()->transcribe($file));
    }

    public function test_falls_back_to_guessed_extension_when_original_name_has_none(): void
    {
        OpenAI::fake([TranscriptionResponse::fake(['text' => 'ok'])]);
        $file = UploadedFile::fake()->create('noext', 10, 'audio/wav');

        $this->assertSame('ok', $this->service()->transcribe($file));
    }

    public function test_defaults_to_m4a_when_extension_cannot_be_determined_at_all(): void
    {
        OpenAI::fake([TranscriptionResponse::fake(['text' => 'ok'])]);
        $file = UploadedFile::fake()->create('noext', 10, 'application/octet-stream');

        $this->assertSame('ok', $this->service()->transcribe($file));
    }
}
