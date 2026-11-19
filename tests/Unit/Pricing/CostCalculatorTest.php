<?php

namespace Tests\Unit\Pricing;

use App\Pricing\CostCalculator;
use App\Pricing\Money;
use App\Pricing\Rates;
use App\Pricing\TokenUsage;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\PixelforgeFixtures;

class CostCalculatorTest extends TestCase
{
    public function test_matches_the_cost_pixelforge_billed_for_a_real_generation(): void
    {
        $gen = PixelforgeFixtures::succeededGeneration();

        $cost = CostCalculator::providerCost(TokenUsage::fromArray($gen['usage']), Rates::fromArray($gen['rates']));

        // 9×5.00 + 280×40.00 + 466×12.50 = 17,070 per 1M → $0.01707 (== /v1/usage cost_usd)
        $this->assertSame('0.017070000', Money::toStorage($cost));
    }

    public function test_applies_the_markup_exactly(): void
    {
        $this->assertSame('0.021337500', Money::toStorage(CostCalculator::price(Money::of('0.017070000'), '1.25')));

        // Smallest unit: one reasoning token = 0.0000125 → ×1.25 = 0.000015625, still exact at 9dp.
        $oneToken = CostCalculator::providerCost(new TokenUsage(0, 0, 0, 1), Rates::fromArray(PixelforgeFixtures::rates()));
        $this->assertSame('0.000012500', Money::toStorage($oneToken));
        $this->assertSame('0.000015625', Money::toStorage(CostCalculator::price($oneToken, '1.25')));
    }

    public function test_summed_per_generation_costs_equal_cost_of_summed_tokens(): void
    {
        // Guards report reconciliation: per-row costs must add up to what /v1/usage reports for the day.
        $rates = Rates::fromArray(PixelforgeFixtures::rates());
        $sum = Money::zero();
        $tokens = [0, 0, 0, 0];

        foreach (range(1, 500) as $i) {
            $u = new TokenUsage(7 + $i % 13, 765 * ($i % 3), 280, ($i * 37) % 501);
            $sum = $sum->plus(CostCalculator::providerCost($u, $rates));
            $tokens = [$tokens[0] + $u->inputText, $tokens[1] + $u->inputImage, $tokens[2] + $u->output, $tokens[3] + $u->reasoning];
        }

        $this->assertSame(
            Money::toStorage(CostCalculator::providerCost(new TokenUsage(...$tokens), $rates)),
            Money::toStorage($sum),
        );
    }

    public static function malformedUsage(): array
    {
        return [
            'missing key' => [['input_text_tokens' => 1, 'input_image_tokens' => 0, 'output_tokens' => 280]],
            'float' => [['input_text_tokens' => 1.5, 'input_image_tokens' => 0, 'output_tokens' => 280, 'reasoning_tokens' => 0]],
            'negative' => [['input_text_tokens' => -1, 'input_image_tokens' => 0, 'output_tokens' => 280, 'reasoning_tokens' => 0]],
        ];
    }

    #[DataProvider('malformedUsage')]
    public function test_rejects_malformed_usage(array $usage): void
    {
        $this->expectException(InvalidArgumentException::class);
        TokenUsage::fromArray($usage);
    }

    public function test_rejects_float_rates(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Rates::fromArray(['input_text_tokens' => 5.0] + PixelforgeFixtures::rates());
    }
}
