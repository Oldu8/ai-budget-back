<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\PlanType;
use PHPUnit\Framework\TestCase;

final class PlanTypeTest extends TestCase
{
    public function test_paid_values_excludes_free(): void
    {
        $this->assertSame(['basic', 'plus', 'premium'], PlanType::paidValues());
    }

    public function test_values_includes_free(): void
    {
        $this->assertSame(['free', 'basic', 'plus', 'premium'], PlanType::values());
    }
}
