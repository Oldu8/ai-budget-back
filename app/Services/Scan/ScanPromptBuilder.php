<?php

namespace App\Services\Scan;

use Illuminate\Support\Collection;

class ScanPromptBuilder
{
    public function build(Collection $categories): string
    {
        $categoriesJson = json_encode(
            $this->groupByParent($categories),
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT,
        );

        return <<<PROMPT
Analyze the image. Is it a financial document (receipt, invoice, bill, payment confirmation)?

If NOT: return {"is_receipt": false, "message": "reason"}

If YES: extract date, total, and line items. Match each item to the best category below. If no specific subcategory fits, use the "Other" subcategory of the most relevant group. Use ONLY category_id values from this list:

{$categoriesJson}

Return strict JSON:
{"is_receipt": true, "date": "YYYY-MM-DD", "total_amount": <int cents>, "items": [{"product": "<name>", "amount": <int cents>, "category_id": <int>, "category_label": "<label>"}]}

Rules:
- Amounts as integers in smallest currency unit (e.g. 150.00 = 15000)
- Sum of item amounts must equal total_amount
- If date unreadable, use today
- If items indistinguishable, single item with total
- JSON only, no extra text
PROMPT;
    }

    private function groupByParent(Collection $categories): array
    {
        $grouped = [];

        foreach ($categories as $category) {
            $parentLabel = $category->parent?->label ?? 'Other';
            $grouped[$parentLabel][] = [
                'id' => $category->id,
                'label' => $category->label,
            ];
        }

        return $grouped;
    }
}
