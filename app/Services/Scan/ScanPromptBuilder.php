<?php

declare(strict_types=1);

namespace App\Services\Scan;

use Illuminate\Support\Collection;

final class ScanPromptBuilder
{
    public function build(Collection $categories): string
    {
        $categoriesJson = json_encode(
            $this->groupByParent($categories),
            JSON_UNESCAPED_UNICODE,
        );

        return <<<PROMPT
Analyze the image. Is it a receipt-like financial document?

Acceptable sources: paper receipt, receipt photo, app screenshot (order summary, delivery, banking), email screenshot, invoice, bill, or payment confirmation — anything that shows line items with prices and a total amount.

If NOT a receipt-like document: set is_receipt to false and message to a short user-facing reason. Do not invent receipt fields.

If YES: set is_receipt to true, then locate the total amount shown on the document — use that as total_amount. Extract the date and line items. Match each item to the best category below. If no specific subcategory fits, use the "Other" subcategory of the most relevant group. Use ONLY category_id values from this list. Each Level 2 entry is [id, label] (integer id then display label):

{$categoriesJson}

Rules for receipt responses:
- Amounts as integers in smallest currency unit (e.g. 150.00 = 15000)
- total_amount MUST be the total shown on the document, not a sum computed from items
- After extracting items, verify: sum(item amounts) == total_amount
  If they differ, re-read each item amount carefully (watch for common OCR errors: 6↔8, 1↔7, 0↔9) and adjust until the sum matches
- If no total is shown, compute total_amount from items
- If date unreadable, use today
- If items indistinguishable, single item with total
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
