<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Scan\ScanResponseSchemaBuilder;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ScanResponseSchemaBuilderTest extends TestCase
{
    #[Test]
    public function it_builds_structured_output_with_discriminated_union(): void
    {
        $builder = new ScanResponseSchemaBuilder;

        $format = $builder->buildResponseFormat();

        $this->assertSame('json_schema', $format['type']);
        $this->assertSame('receipt_scan', $format['json_schema']['name']);
        $this->assertTrue($format['json_schema']['strict']);

        $branches = $format['json_schema']['schema']['oneOf'];
        $this->assertCount(2, $branches);

        $notReceipt = $branches[0];
        $this->assertSame(['is_receipt', 'message'], $notReceipt['required']);
        $this->assertFalse($notReceipt['properties']['is_receipt']['const']);
        $this->assertNotContains('date', array_keys($notReceipt['properties']));
        $this->assertNotContains('items', array_keys($notReceipt['properties']));

        $receipt = $branches[1];
        $this->assertSame(['is_receipt', 'date', 'currency', 'total_amount', 'items'], $receipt['required']);
        $this->assertTrue($receipt['properties']['is_receipt']['const']);
        $this->assertSame(1, $receipt['properties']['items']['minItems']);
    }
}
