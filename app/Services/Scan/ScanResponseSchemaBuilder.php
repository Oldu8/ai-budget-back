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
     * @return array<string, mixed>
     */
    public function buildSchema(): array
    {
        return [
            'oneOf' => [
                $this->notReceiptBranch(),
                $this->receiptBranch(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function notReceiptBranch(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'is_receipt' => [
                    'type' => 'boolean',
                    'const' => false,
                    'description' => 'Image is not a financial document.',
                ],
                'message' => [
                    'type' => 'string',
                    'description' => 'Short reason shown to the user.',
                ],
            ],
            'required' => ['is_receipt', 'message'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function receiptBranch(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'is_receipt' => [
                    'type' => 'boolean',
                    'const' => true,
                    'description' => 'Image is a financial document.',
                ],
                'date' => [
                    'type' => 'string',
                    'description' => 'Transaction date in YYYY-MM-DD format.',
                ],
                'currency' => [
                    'type' => 'string',
                    'description' => 'ISO 4217 code the receipt is denominated in (detected, or the user default).',
                ],
                'total_amount' => [
                    'type' => 'integer',
                    'minimum' => 0,
                    'description' => 'Printed receipt total in smallest currency unit.',
                ],
                'items' => [
                    'type' => 'array',
                    'minItems' => 1,
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
            'required' => ['is_receipt', 'date', 'currency', 'total_amount', 'items'],
            'additionalProperties' => false,
        ];
    }
}
