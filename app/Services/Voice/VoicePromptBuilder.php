<?php

declare(strict_types=1);

namespace App\Services\Voice;

use Illuminate\Support\Collection;

final class VoicePromptBuilder
{
    public function build(Collection $categories, string $today, string $currencyCode): string
    {
        $categoriesJson = json_encode(
            $this->groupByParent($categories),
            JSON_UNESCAPED_UNICODE,
        );

        return <<<PROMPT
You receive plain text: a transcript of the user describing spending or unrelated speech.

Today is {$today}.

The user's accounting currency is {$currencyCode} (ISO 4217). Use it only to interpret amounts correctly. Do not return a currency field or code in JSON — only integer amounts in minor units.

Decide if the transcript describes a real expense made by the user. If not, return:
{"is_expense": false, "message": "reason"}

If it is an expense, extract date, total amount in minor units, and line items.

Use ONLY category_id values from this allowed category list. Each Level 2 entry is [id, label] (integer id then display label):
{$categoriesJson}

Return strict JSON only:
{"is_expense": true, "date": "YYYY-MM-DD", "total_amount": <int>, "items": [{"product": "<name>", "amount": <int>, "category_id": <int>, "category_label": "<label>"}]}

Rules:
- Amounts must be integers in the smallest currency unit: 24.50 = 2450
- Do not return currency in the JSON
- Sum of item amounts must equal total_amount
- If date is missing, use today's date: {$today}
- If only a single lump sum is clear, create one item with that amount
- If several items are clear, create several items
- If an amount is missing or unclear, return {"is_expense": false, "message": "Amount is missing or unclear"}
- Match each item to the closest allowed Level 2 category using only ids from the list above
- Never invent category_id values
- product must be a short human-readable label, for example: Groceries, Coffee, Fuel, Parking
- JSON only, no markdown, no comments, no extra text
PROMPT;
    }

    private function groupByParent(Collection $categories): array
    {
        $grouped = [];

        foreach ($categories as $category) {
            $parentLabel = $category->parent->label ?? 'Other';
            $grouped[$parentLabel][] = [(int) $category->id, $category->label];
        }

        return $grouped;
    }
}
