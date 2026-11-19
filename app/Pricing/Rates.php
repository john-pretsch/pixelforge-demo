<?php

namespace App\Pricing;

use Brick\Math\BigDecimal;
use InvalidArgumentException;

/**
 * USD per 1M tokens. Built either from GET /v1/pricing (estimates) or from the
 * `rates` locked on a generation at creation (actual cost).
 */
final readonly class Rates
{
    public function __construct(
        public BigDecimal $inputText,
        public BigDecimal $inputImage,
        public BigDecimal $output,
        public BigDecimal $reasoning,
    ) {}

    /** @param array<string, mixed> $rates Pixelforge `rates` object (decimal strings). */
    public static function fromArray(array $rates): self
    {
        $get = static function (string $key) use ($rates): BigDecimal {
            if (! isset($rates[$key]) || (! is_string($rates[$key]) && ! is_int($rates[$key]))) {
                throw new InvalidArgumentException("Pixelforge rates missing or non-decimal `{$key}`.");
            }

            return BigDecimal::of($rates[$key]);
        };

        return new self(
            inputText: $get('input_text_tokens'),
            inputImage: $get('input_image_tokens'),
            output: $get('output_tokens'),
            reasoning: $get('reasoning_tokens'),
        );
    }
}
