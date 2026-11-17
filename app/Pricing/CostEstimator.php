<?php

namespace App\Pricing;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * Pre-submission estimate. Text and reasoning tokens are only known after the
 * generation completes, so the estimate is a [min, max] range that the actual
 * cost falls inside.
 */
final class CostEstimator
{
    public static function estimate(
        PricingTable $pricing,
        string $markup,
        string $prompt,
        string $size,
        string $quality,
        int $referenceImageCount = 0,
    ): Estimate {
        $output = $pricing->outputTokens[$quality][$size]
            ?? throw new InvalidArgumentException("Unknown quality/size: {$quality} {$size}.");
        $reasoning = $pricing->reasoningTokens[$quality]
            ?? throw new InvalidArgumentException("Unknown quality: {$quality}.");

        [$textMin, $textMax] = self::textTokenRange($pricing, $prompt);
        $imageTokens = $referenceImageCount * $pricing->tokensPerReferenceImage;

        $min = new TokenUsage($textMin, $imageTokens, $output, (int) $reasoning['min']);
        $max = new TokenUsage($textMax, $imageTokens, $output, (int) $reasoning['max']);

        $costMin = CostCalculator::providerCost($min, $pricing->rates);
        $costMax = CostCalculator::providerCost($max, $pricing->rates);

        return new Estimate(
            tokensMin: $min,
            tokensMax: $max,
            providerCostMin: $costMin,
            providerCostMax: $costMax,
            priceMin: CostCalculator::price($costMin, $markup),
            priceMax: CostCalculator::price($costMax, $markup),
        );
    }

    /**
     * About ceil(characters / 4), accurate to ±variance until completion.
     *
     * @return array{int, int}
     */
    public static function textTokenRange(PricingTable $pricing, string $prompt): array
    {
        $base = BigDecimal::of(mb_strlen($prompt))
            ->dividedBy($pricing->charactersPerTextToken, 0, RoundingMode::Ceiling);

        $one = BigDecimal::one();
        $min = $base->multipliedBy($one->minus($pricing->textTokenVariance))->toScale(0, RoundingMode::Floor);
        $max = $base->multipliedBy($one->plus($pricing->textTokenVariance))->toScale(0, RoundingMode::Ceiling);

        return [max(0, $min->toInt()), $max->toInt()];
    }
}
