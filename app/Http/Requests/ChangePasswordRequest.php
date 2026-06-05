<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ChangePasswordRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string'],
            'new_password' => ['required', 'string', 'min:6', 'different:current_password'],
            // Optional: this device's refresh token, kept alive while other sessions are revoked.
            'refresh_token' => ['nullable', 'string'],
        ];
    }
}
