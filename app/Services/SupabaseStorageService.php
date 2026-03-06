<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class SupabaseStorageService
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
        $path = "{$userId}/" . Str::uuid() . ".{$ext}";

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

    public function download(string $path): string
    {
        $response = Http::withHeaders([
            'Authorization' => "Bearer {$this->serviceRole}",
        ])->get("{$this->url}/storage/v1/object/{$this->bucket}/{$path}");

        if ($response->failed()) {
            throw new RuntimeException(
                "Supabase Storage download failed: {$response->status()} {$response->body()}"
            );
        }

        return base64_encode($response->body());
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
}
