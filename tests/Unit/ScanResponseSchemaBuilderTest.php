<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Scan\ScanResponseSchemaBuilder;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ScanResponseSchemaBuilderTest extends TestCase
{
    #[Test]
    public function it_builds_a_flat_object_schema_nulling_only_message_and_date(): void
    {
        $builder = new ScanResponseSchemaBuilder;

        $format = $builder->buildResponseFormat();

        $this->assertSame('json_schema', $format['type']);
        $this->assertSame('receipt_scan', $format['json_schema']['name']);
        $this->assertTrue($format['json_schema']['strict']);

        $schema = $format['json_schema']['schema'];

        // Root must be a plain object: OpenAI Structured Outputs (strict mode)
        // rejects a top-level oneOf/anyOf.
        $this->assertSame('object', $schema['type']);
        $this->assertArrayNotHasKey('oneOf', $schema);

        $this->assertSame(
            ['is_receipt', 'message', 'date', 'currency', 'total_amount', 'items'],
            $schema['required'],
        );
        $this->assertFalse($schema['additionalProperties']);

        $this->assertSame('boolean', $schema['properties']['is_receipt']['type']);
        $this->assertSame(['string', 'null'], $schema['properties']['message']['type']);
        $this->assertSame(['string', 'null'], $schema['properties']['date']['type']);
        // currency/total_amount/items always have a real value (default currency,
        // 0, or []) — the prompt guarantees a fallback, so no null is needed.
        $this->assertSame('string', $schema['properties']['currency']['type']);
        $this->assertSame('integer', $schema['properties']['total_amount']['type']);
        $this->assertSame('array', $schema['properties']['items']['type']);

        $itemSchema = $schema['properties']['items']['items'];
        $this->assertSame(
            ['product', 'amount', 'category_id', 'category_label'],
            $itemSchema['required'],
        );
    }
}
