<?php

namespace App\Pricing;

use Brick\Math\BigDecimal;
use InvalidArgumentException;

/**
 * Current rates plus token-estimation constants, from GET /v1/pricing.
 * Sizes and qualities are read from here, not hardcoded.
 */
final readonly class PricingTable
{
    /**
     * @param  array<string, array<string, int>>  $outputTokens  quality => size => tokens
     * @param  array<string, array{min: int, max: int}>  $reasoningTokens  quality => range
     */
    public function __construct(
        public Rates $rates,
        public int $charactersPerTextToken,
        public BigDecimal $textTokenVariance,
        public int $tokensPerReferenceImage,
        public array $outputTokens,
        public array $reasoningTokens,
    ) {}

    /** @param array<string, mixed> $pricing Body of GET /v1/pricing. */
    public static function fromArray(array $pricing): self
    {
        $tokens = $pricing['tokens'] ?? null;

        if (! is_array($tokens) || ! is_array($pricing['rates'] ?? null)) {
            throw new InvalidArgumentException('Malformed Pixelforge pricing response.');
        }

        return new self(
            rates: Rates::fromArray($pricing['rates']),
            charactersPerTextToken: (int) $tokens['characters_per_text_token'],
            // Variance arrives as a JSON number (0.15); go via string so it stays exact.
            textTokenVariance: BigDecimal::of((string) $tokens['text_token_variance']),
            tokensPerReferenceImage: (int) $tokens['per_reference_image'],
            outputTokens: $tokens['output'],
            reasoningTokens: $tokens['reasoning'],
        );
    }

    /** @return list<string> */
    public function qualities(): array
    {
        return array_keys($this->outputTokens);
    }

    /** @return list<string> */
    public function sizes(): array
    {
        $first = array_key_first($this->outputTokens);

        return $first === null ? [] : array_keys($this->outputTokens[$first]);
    }
}
