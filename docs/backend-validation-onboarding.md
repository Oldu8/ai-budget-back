# Backend Validation Rules for Onboarding Flow

Copy these rules into your Laravel backend for the onboarding/settings endpoints.

## PUT /api/me (Profile Update)

```php
$request->validate([
    'first_name' => [
        'required',
        'string',
        'min:2',
        'max:50',
        'regex:/^[\p{L}\s\-']+$/u',
    ],
    'last_name' => [
        'required',
        'string',
        'min:2',
        'max:50',
        'regex:/^[\p{L}\s\-']+$/u',
    ],
]);
```

Optional: trim input before validation:

```php
$validated = $request->validate([...]);
$validated['first_name'] = preg_replace('/\s+/', ' ', trim($validated['first_name']));
$validated['last_name'] = preg_replace('/\s+/', ' ', trim($validated['last_name']));
```

## POST /api/me/settings (Create Settings)

```php
$currencies = ['USD', 'EUR', 'GBP', 'UAH', 'PLN', 'CHF', 'CAD', 'AUD', 'JPY', 'CNY', 'RUB', 'MXN', 'INR', 'BRL'];
$languages = ['en', 'uk', 'es'];
$timezones = [
    'UTC', 'Europe/Kyiv', 'Europe/Madrid', 'Europe/London', 'Europe/Paris',
    'Europe/Berlin', 'Europe/Warsaw', 'Europe/Moscow', 'America/New_York',
    'America/Chicago', 'America/Denver', 'America/Los_Angeles',
    'America/Mexico_City', 'America/Sao_Paulo', 'Asia/Tokyo', 'Asia/Shanghai',
    'Asia/Dubai', 'Australia/Sydney',
];

$request->validate([
    'currency' => ['required', 'string', Rule::in($currencies)],
    'language' => ['required', 'string', Rule::in($languages)],
    'timezone' => ['required', 'string', Rule::in($timezones)],
]);
```

## PUT /api/me/settings (Update Settings)

Same as POST, but all fields can be optional if you support partial updates:

```php
$request->validate([
    'currency' => ['sometimes', 'required', 'string', Rule::in($currencies)],
    'language' => ['sometimes', 'required', 'string', Rule::in($languages)],
    'timezone' => ['sometimes', 'required', 'string', Rule::in($timezones)],
]);
```

## FormRequest Example

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMeRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'first_name' => [
                'required',
                'string',
                'min:2',
                'max:50',
                'regex:/^[\p{L}\s\-']+$/u',
            ],
            'last_name' => [
                'required',
                'string',
                'min:2',
                'max:50',
                'regex:/^[\p{L}\s\-']+$/u',
            ],
        ];
    }
}

class CreateSettingsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'currency' => ['required', 'string', Rule::in($this->allowedCurrencies())],
            'language' => ['required', 'string', Rule::in(['en', 'uk', 'es'])],
            'timezone' => ['required', 'string', Rule::in($this->allowedTimezones())],
        ];
    }

    private function allowedCurrencies(): array
    {
        return ['USD', 'EUR', 'GBP', 'UAH', 'PLN', 'CHF', 'CAD', 'AUD', 'JPY', 'CNY', 'RUB', 'MXN', 'INR', 'BRL'];
    }

    private function allowedTimezones(): array
    {
        return [
            'UTC', 'Europe/Kyiv', 'Europe/Madrid', 'Europe/London', 'Europe/Paris',
            'Europe/Berlin', 'Europe/Warsaw', 'Europe/Moscow', 'America/New_York',
            'America/Chicago', 'America/Denver', 'America/Los_Angeles',
            'America/Mexico_City', 'America/Sao_Paulo', 'Asia/Tokyo', 'Asia/Shanghai',
            'Asia/Dubai', 'Australia/Sydney',
        ];
    }
}
```

## Notes

- Database columns `first_name` and `last_name` may remain nullable for other flows; validation above applies to this onboarding flow.
- The regex `^[\p{L}\s\-']+$` allows Unicode letters, spaces, hyphens, and apostrophes.
- Keep the allowed currency/language/timezone lists in sync with the frontend constants.
