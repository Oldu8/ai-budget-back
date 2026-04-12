<?php

namespace App\Http\Responses;

class SummaryResponse
{
    public function __construct(
        public readonly string $from,
        public readonly string $to,
        /** @var array<string, array<string, int>> date => category_id => amount (cents) */
        public readonly array $days,
        /** @var array{totals_by_category: array<string, int>, grand_total: int} */
        public readonly array $meta,
    ) {}

    public function toArray(): array
    {
        return [
            'from' => $this->from,
            'to' => $this->to,
            'days' => $this->days,
            'meta' => $this->meta,
        ];
    }
}
