<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateMeRequest extends FormRequest
{
    private const NAME_RULES = ['required', 'string', 'min:2', 'max:50', 'regex:/^[\p{L}\s\-\']+$/u'];

    public function rules(): array
    {
        return [
            'first_name' => self::NAME_RULES,
            'last_name' => self::NAME_RULES,
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['first_name', 'last_name'] as $field) {
            if ($this->filled($field) && is_string($this->$field)) {
                $this->merge([$field => preg_replace('/\s+/', ' ', trim($this->$field))]);
            }
        }
    }
}
