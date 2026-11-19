<?php

namespace App\Pricing;

use Brick\Math\BigDecimal;

/** Pure, exact cost math. No floats, no I/O. */
final class CostCalculator
{
    /** Provider cost in USD = Σ(tokens × rate per 1M) / 1,000,000. */
    public static function providerCost(TokenUsage $usage, Rates $rates): BigDecimal
    {
        $perMillion = $rates->inputText->multipliedBy($usage->inputText)
            ->plus($rates->inputImage->multipliedBy($usage->inputImage))
            ->plus($rates->output->multipliedBy($usage->output))
            ->plus($rates->reasoning->multipliedBy($usage->reasoning));

        return Money::normalize($perMillion->dividedByExact(1_000_000));
    }

    /** Customer price = provider cost × markup (e.g. "1.25"). */
    public static function price(BigDecimal $providerCost, string $markup): BigDecimal
    {
        return Money::normalize($providerCost->multipliedBy($markup));
    }
}
