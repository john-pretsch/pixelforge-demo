<?php

namespace Tests\Unit\Pricing;

use App\Pricing\Money;
use Brick\Math\RoundingMode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public static function displayCases(): array
    {
        return [
            'zero' => ['0.000000000', '$0.00'],
            'cents' => ['1.234500000', '$1.23'],
            'half up' => ['1.235000000', '$1.24'],
            'sub-cent keeps 4dp' => ['0.001400000', '$0.0014'],
            'rounds up to a cent' => ['0.009990000', '$0.01'],
            'tiny' => ['0.000015625', '<$0.0001'],
        ];
    }

    #[DataProvider('displayCases')]
    public function test_formats_for_display(string $amount, string $expected): void
    {
        $this->assertSame($expected, Money::format($amount));
    }

    public function test_range_ends_round_outward(): void
    {
        $this->assertSame('$0.01', Money::format('0.014050000', RoundingMode::Floor));
        $this->assertSame('$0.03', Money::format('0.021887500', RoundingMode::Ceiling));
    }

    public function test_null_passes_through(): void
    {
        $this->assertNull(Money::format(null));
    }
}
