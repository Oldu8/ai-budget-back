<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AppUserSettingsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'currency' => ['required', 'string', 'max:10'],
            'language' => ['required', 'string', 'max:10'],
            'timezone' => ['required', 'string', 'max:64', 'timezone:all'],
        ];
    }
}
