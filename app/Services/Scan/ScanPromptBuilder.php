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

If NOT a receipt-like document: set is_receipt to false and message to a one-sentence English reason to show the user.

If YES: set is_receipt to true, then extract:

DATE — use the transaction (purchase) date, not the print or issue date. Format: YYYY-MM-DD. If unreadable, use today.

TOTAL AMOUNT — use the final printed total (the "Total" or "Grand Total" line, not the subtotal). Express in smallest currency unit (e.g. 150.00 → 15000). If no total line exists, sum the item amounts.

ITEMS — one entry per line item:
  - product: item name as printed on the receipt
  - amount: integer in smallest currency unit
  - category_id + category_label: best match from the list below

Special lines:
  - Taxes, delivery fees, service charges → include as separate items; assign the most relevant category
  - Discounts and promotions → ignore these lines; use the net amount actually paid for each item
  - If line items are indistinguishable → single item with total_amount and the most relevant category

CATEGORIES — the keys below (e.g. "Food", "Housing") are group names, NOT valid category_id values. Use ONLY the integer IDs from the nested [id, label] pairs. Set category_label to the label from the same pair. If no specific subcategory fits, use the "Other" subcategory of the most relevant group:

{$categoriesJson}

AMOUNT CONSISTENCY:
- After extracting items verify: sum(item amounts) == total_amount
- If they differ, re-read each item carefully (common OCR errors: 6↔8, 1↔7, 0↔9)
- If still mismatched, proportionally scale item amounts to match — never change total_amount
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
