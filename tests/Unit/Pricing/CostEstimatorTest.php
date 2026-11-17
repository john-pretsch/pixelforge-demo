<?php

namespace Tests\Unit\Pricing;

use App\Pricing\CostCalculator;
use App\Pricing\CostEstimator;
use App\Pricing\Money;
use App\Pricing\PricingTable;
use App\Pricing\Rates;
use App\Pricing\TokenUsage;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tests\Support\PixelforgeFixtures;

class CostEstimatorTest extends TestCase
{
    private PricingTable $pricing;

    protected function setUp(): void
    {
        $this->pricing = PricingTable::fromArray(PixelforgeFixtures::pricing());
    }

    public function test_reads_sizes_and_qualities_from_the_pricing_table(): void
    {
        $this->assertSame(['low', 'medium', 'high'], $this->pricing->qualities());
        $this->assertSame(['1024x1024', '1536x1024', '1024x1536'], $this->pricing->sizes());
    }

    public function test_text_tokens_are_ceil_chars_over_four_plus_minus_fifteen_percent(): void
    {
        // 37 chars → ceil(37/4) = 10 → [floor(8.5), ceil(11.5)] = [8, 12]
        $this->assertSame([8, 12], CostEstimator::textTokenRange($this->pricing, str_repeat('a', 37)));
        // Multibyte characters count as characters, not bytes.
        $this->assertSame([8, 12], CostEstimator::textTokenRange($this->pricing, str_repeat('é', 40)));
    }

    public function test_exact_range_for_low_quality_square(): void
    {
        $e = CostEstimator::estimate($this->pricing, '1.25', '[succeed] a red cube on a white table', '1024x1024', 'low');

        // min: 8×5 + 280×40 + 0×12.5   = 11,240 → 0.01124 → ×1.25 = 0.01405
        // max: 12×5 + 280×40 + 500×12.5 = 17,510 → 0.01751 → ×1.25 = 0.0218875
        $this->assertSame('0.011240000', Money::toStorage($e->providerCostMin));
        $this->assertSame('0.017510000', Money::toStorage($e->providerCostMax));
        $this->assertSame('0.014050000', Money::toStorage($e->priceMin));
        $this->assertSame('0.021887500', Money::toStorage($e->priceMax));
        $this->assertSame(['min' => '$0.01', 'max' => '$0.03'], $e->toArray()['display']);
    }

    public function test_each_reference_image_is_765_input_image_tokens(): void
    {
        $e = CostEstimator::estimate($this->pricing, '1.25', 'x', '1536x1024', 'high', 4);

        $this->assertSame(3060, $e->tokensMin->inputImage);
        $this->assertSame(3060, $e->tokensMax->inputImage);
        $this->assertSame(6450, $e->tokensMin->output);
        $this->assertSame(8000, $e->tokensMax->reasoning);
    }

    public function test_range_contains_the_actual_price_of_a_real_generation(): void
    {
        $gen = PixelforgeFixtures::succeededGeneration();
        $e = CostEstimator::estimate($this->pricing, '1.25', $gen['prompt'], $gen['size'], $gen['quality']);

        $actual = CostCalculator::price(
            CostCalculator::providerCost(TokenUsage::fromArray($gen['usage']), Rates::fromArray($gen['rates'])),
            '1.25',
        );

        $this->assertTrue($e->contains($actual));
    }

    public function test_rejects_unknown_size(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CostEstimator::estimate($this->pricing, '1.25', 'x', '512x512', 'low');
    }
}
