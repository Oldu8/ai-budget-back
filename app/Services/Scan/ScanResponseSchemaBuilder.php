<?php

declare(strict_types=1);

namespace App\Services\Scan;

/**
 * Builds OpenAI Structured Outputs schema for receipt scan responses.
 */
// [SOLID:S] Schema shape is isolated from prompt text and HTTP orchestration
final class ScanResponseSchemaBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function buildResponseFormat(): array
    {
        return [
            'type' => 'json_schema',
            'json_schema' => [
                'name' => 'receipt_scan',
                'strict' => true,
                'schema' => $this->buildSchema(),
            ],
        ];
    }

    /**
     * OpenAI Structured Outputs (strict mode) rejects a top-level oneOf/anyOf —
     * the root schema must be a plain object. So both branches are flattened into
     * one object, always listing every key in `required` (strict mode has no true
     * "optional" — nullable is the only workaround, and only used where a null is
     * actually meaningful downstream):
     *  - `message` is only shown to the user when is_receipt is false.
     *  - `date` may be unreadable on a real receipt; the caller falls back to the
     *    user's local "today" rather than rejecting the scan.
     *  - `currency`/`total_amount`/`items` are never null: the prompt always gives
     *    the model a default currency and a "sum item amounts" fallback, and an
     *    empty/missing `items` is treated as "not actually a receipt" by the caller.
     *
     * @return array<string, mixed>
     */
    public function buildSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'is_receipt' => [
                    'type' => 'boolean',
                    'description' => 'Whether the image is a financial document (receipt/invoice).',
                ],
                'message' => [
                    'type' => ['string', 'null'],
                    'description' => 'Short reason shown to the user when is_receipt is false; null otherwise.',
                ],
                'date' => [
                    'type' => ['string', 'null'],
                    'description' => 'Transaction date in YYYY-MM-DD format. Null only if truly unreadable on a real receipt.',
                ],
                'currency' => [
                    'type' => 'string',
                    'description' => 'ISO 4217 code the receipt is denominated in (detected, or the given default currency if not a receipt or unreadable).',
                ],
                'total_amount' => [
                    'type' => 'integer',
                    'minimum' => 0,
                    'description' => 'Printed receipt total in smallest currency unit; 0 if not a receipt.',
                ],
                'items' => [
                    'type' => 'array',
                    'description' => 'Line items; empty array if not a receipt.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'product' => [
                                'type' => 'string',
                                'description' => 'Line item name.',
                            ],
                            'amount' => [
                                'type' => 'integer',
                                'minimum' => 0,
                                'description' => 'Line amount in smallest currency unit.',
                            ],
                            'category_id' => [
                                'type' => 'integer',
                                'description' => 'Category id from the provided list.',
                            ],
                            'category_label' => [
                                'type' => 'string',
                                'description' => 'Category label from the provided list.',
                            ],
                        ],
                        'required' => ['product', 'amount', 'category_id', 'category_label'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['is_receipt', 'message', 'date', 'currency', 'total_amount', 'items'],
            'additionalProperties' => false,
        ];
    }
}
