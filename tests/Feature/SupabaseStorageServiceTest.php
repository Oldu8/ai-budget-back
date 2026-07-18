<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\SupabaseStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

final class SupabaseStorageServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): SupabaseStorageService
    {
        return app(SupabaseStorageService::class);
    }

    public function test_upload_succeeds(): void
    {
        Http::fake(['*/storage/v1/object/*' => Http::response(['Key' => 'ok'], 200)]);
        $file = UploadedFile::fake()->create('receipt.jpg', 10, 'image/jpeg');

        $path = $this->service()->upload($file, 1);

        $this->assertStringEndsWith('.jpg', $path);
    }

    public function test_upload_throws_when_request_fails(): void
    {
        Http::fake(['*/storage/v1/object/*' => Http::response(['message' => 'nope'], 500)]);
        $file = UploadedFile::fake()->create('receipt.jpg', 10, 'image/jpeg');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Supabase Storage upload failed/');

        $this->service()->upload($file, 1);
    }

    public function test_create_signed_url_throws_when_request_fails(): void
    {
        Http::fake(['*/storage/v1/object/sign/*' => Http::response(['message' => 'nope'], 500)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Supabase Storage signed URL failed/');

        $this->service()->createSignedUrl('1/some-file.jpg');
    }

    public function test_create_signed_url_throws_when_response_is_missing_url(): void
    {
        Http::fake(['*/storage/v1/object/sign/*' => Http::response(['unexpected' => 'shape'], 200)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Supabase Storage signed URL response missing URL');

        $this->service()->createSignedUrl('1/some-file.jpg');
    }

    public function test_create_signed_url_succeeds_and_prefixes_relative_url(): void
    {
        Http::fake(['*/storage/v1/object/sign/*' => Http::response(['signedURL' => '/object/sign/abc'], 200)]);

        $result = $this->service()->createSignedUrl('1/some-file.jpg');

        $this->assertStringEndsWith('/object/sign/abc', $result['url']);
    }
}
