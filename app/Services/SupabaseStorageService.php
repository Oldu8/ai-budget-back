<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

final class SupabaseStorageService
{
    private string $url;

    private string $serviceRole;

    private string $bucket;

    public function __construct()
    {
        $this->url = rtrim(config('services.supabase.url'), '/');
        $this->serviceRole = config('services.supabase.service_role');
        $this->bucket = config('services.supabase.storage_bucket');
    }

    public function upload(UploadedFile $file, int $userId): string
    {
        $ext = $file->getClientOriginalExtension() ?: 'jpg';
        $path = "{$userId}/".Str::uuid().".{$ext}";

        $response = Http::withHeaders([
            'Authorization' => "Bearer {$this->serviceRole}",
            'Content-Type' => $file->getMimeType(),
        ])->withBody(
            $file->getContent(),
            $file->getMimeType(),
        )->post("{$this->url}/storage/v1/object/{$this->bucket}/{$path}");

        if ($response->failed()) {
            throw new RuntimeException(
                "Supabase Storage upload failed: {$response->status()} {$response->body()}"
            );
        }

        return $path;
    }

    public function delete(string $path): void
    {
        $response = Http::withHeaders([
            'Authorization' => "Bearer {$this->serviceRole}",
        ])->delete("{$this->url}/storage/v1/object/{$this->bucket}/{$path}");

        if ($response->failed()) {
            throw new RuntimeException(
                "Supabase Storage delete failed: {$response->status()} {$response->body()}"
            );
        }
    }

    /**
     * @return array{url: string, expires_in: int}
     */
    public function createSignedUrl(string $path, int $expiresSeconds = 300): array
    {
        $encodedPath = implode('/', array_map('rawurlencode', explode('/', $path)));

        $response = Http::withHeaders([
            'Authorization' => "Bearer {$this->serviceRole}",
            'apikey' => $this->serviceRole,
            'Content-Type' => 'application/json',
        ])->post("{$this->url}/storage/v1/object/sign/{$this->bucket}/{$encodedPath}", [
            'expiresIn' => $expiresSeconds,
        ]);

        if ($response->failed()) {
            throw new RuntimeException(
                "Supabase Storage signed URL failed: {$response->status()} {$response->body()}"
            );
        }

        $body = $response->json();
        $signed = $body['signedURL'] ?? $body['signedUrl'] ?? null;
        if (! is_string($signed) || $signed === '') {
            throw new RuntimeException('Supabase Storage signed URL response missing URL');
        }

        if (str_starts_with($signed, '/')) {
            $signed = rtrim($this->url, '/').$signed;
        }

        return [
            'url' => $signed,
            'expires_in' => $expiresSeconds,
        ];
    }
}
