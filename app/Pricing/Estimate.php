<?php

namespace App\Pricing;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final readonly class Estimate
{
    public function __construct(
        public TokenUsage $tokensMin,
        public TokenUsage $tokensMax,
        public BigDecimal $providerCostMin,
        public BigDecimal $providerCostMax,
        public BigDecimal $priceMin,
        public BigDecimal $priceMax,
    ) {}

    public function contains(BigDecimal $price): bool
    {
        return ! $price->isLessThan($this->priceMin) && ! $price->isGreaterThan($this->priceMax);
    }

    /** Customer-facing shape: exact strings plus a display range that never understates. */
    public function toArray(): array
    {
        return [
            'price_min' => Money::toStorage($this->priceMin),
            'price_max' => Money::toStorage($this->priceMax),
            'display' => [
                'min' => Money::format($this->priceMin, RoundingMode::Floor),
                'max' => Money::format($this->priceMax, RoundingMode::Ceiling),
            ],
            'tokens' => [
                'min' => $this->tokensMin->toArray(),
                'max' => $this->tokensMax->toArray(),
            ],
        ];
    }
}
