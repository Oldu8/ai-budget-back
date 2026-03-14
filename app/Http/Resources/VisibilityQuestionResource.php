<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VisibilityQuestionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'visibility_tag' => $this->resource['visibility_tag'],
            'question_key' => $this->resource['question_key'],
            'type' => $this->resource['type'],
            'order' => $this->resource['order'],
            'translation_key' => $this->resource['translation_key'],
        ];
    }
}
