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

    /** @param string|null $bucket Overrides the default (receipts) bucket, e.g. for the public blog-covers bucket. */
    public function __construct(?string $bucket = null)
    {
        $this->url = rtrim(config('services.supabase.url'), '/');
        $this->serviceRole = config('services.supabase.service_role');
        $this->bucket = $bucket ?? config('services.supabase.storage_bucket');
    }

    /** @param string|int|null $prefix Folder to scope the file under, e.g. a user id. Omit for a flat bucket. */
    public function upload(UploadedFile $file, string|int|null $prefix = null): string
    {
        $ext = $file->getClientOriginalExtension() ?: 'jpg';
        $filename = Str::uuid().".{$ext}";
        $path = $prefix !== null ? "{$prefix}/{$filename}" : $filename;

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

    /** Permanent URL for an object in a public bucket (no signing/expiry). */
    public function publicUrl(string $path): string
    {
        $encodedPath = implode('/', array_map('rawurlencode', explode('/', $path)));

        return "{$this->url}/storage/v1/object/public/{$this->bucket}/{$encodedPath}";
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
