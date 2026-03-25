<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransactionResource extends JsonResource
{
    public static $wrap = 'data';

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'created_by' => $this->created_by->value,
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'date' => $this->date?->format('Y-m-d'),
            'total_amount' => $this->total_amount,
            'img_path' => $this->img_path,
            'raw_ai_data' => $this->raw_ai_data,
            'items' => TransactionItemResource::collection($this->whenLoaded('items')),
        ];
    }
}
