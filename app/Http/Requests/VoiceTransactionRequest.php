<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class VoiceTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string|int>>
     */
    public function rules(): array
    {
        return [
            'audio' => [
                'required',
                'file',
                'max:5120',
                'mimes:mp3,mpeg,mp4,m4a,wav,webm,ogg',
                'mimetypes:audio/mpeg,audio/mp4,audio/x-m4a,audio/wav,audio/webm,audio/ogg,video/mp4',
            ],
        ];
    }
}
