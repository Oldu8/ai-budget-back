<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AppUserSettingsRequest extends FormRequest
{
    public function rules(): array
    {
        $sometimes = $this->isMethod('PUT');

        $baseRules = ['required', 'string', 'filled', 'min:1'];

        return [
            'currency' => array_merge(
                $sometimes ? ['sometimes'] : [],
                $baseRules,
                [Rule::in($this->allowedCurrencies())]
            ),
            'language' => array_merge(
                $sometimes ? ['sometimes'] : [],
                $baseRules,
                [Rule::in($this->allowedLanguages())]
            ),
            'timezone' => array_merge(
                $sometimes ? ['sometimes'] : [],
                $baseRules,
                [Rule::in($this->allowedTimezones())]
            ),
        ];
    }

    private function allowedCurrencies(): array
    {
        return ['USD', 'EUR', 'GBP', 'UAH', 'PLN', 'CHF', 'CAD', 'AUD', 'JPY', 'CNY', 'RUB', 'MXN', 'INR', 'BRL'];
    }

    private function allowedLanguages(): array
    {
        return ['en', 'uk', 'es'];
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
