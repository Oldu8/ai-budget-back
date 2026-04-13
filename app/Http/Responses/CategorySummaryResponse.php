<?php

namespace App\Http\Responses;

class CategorySummaryResponse
{
    public function __construct(
        /** @var array{id: int, slug: string, label: string} */
        public readonly array $category,
        public readonly string $from,
        public readonly string $to,
        /** @var array<string, array<string, int>> date => subcategory_id => amount (cents) */
        public readonly array $days,
        /** @var array{totals_by_subcategory: array<string, int>, grand_total: int} */
        public readonly array $meta,
    ) {}

    public function toArray(): array
    {
        return [
            'category' => $this->category,
            'from' => $this->from,
            'to' => $this->to,
            'days' => $this->days,
            'meta' => $this->meta,
        ];
    }
}
