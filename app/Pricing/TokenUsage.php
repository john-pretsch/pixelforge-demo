<?php

namespace App\Pricing;

use InvalidArgumentException;

/** Billable token counts, as in a generation's `usage` object. */
final readonly class TokenUsage
{
    public function __construct(
        public int $inputText,
        public int $inputImage,
        public int $output,
        public int $reasoning,
    ) {}

    /** @param array<string, mixed> $usage */
    public static function fromArray(array $usage): self
    {
        $get = static function (string $key) use ($usage): int {
            if (! isset($usage[$key]) || ! is_int($usage[$key]) || $usage[$key] < 0) {
                throw new InvalidArgumentException("Pixelforge usage missing or invalid `{$key}`.");
            }

            return $usage[$key];
        };

        return new self(
            inputText: $get('input_text_tokens'),
            inputImage: $get('input_image_tokens'),
            output: $get('output_tokens'),
            reasoning: $get('reasoning_tokens'),
        );
    }

    public function total(): int
    {
        return $this->inputText + $this->inputImage + $this->output + $this->reasoning;
    }

    /** @return array<string, int> */
    public function toArray(): array
    {
        return [
            'input_text_tokens' => $this->inputText,
            'input_image_tokens' => $this->inputImage,
            'output_tokens' => $this->output,
            'reasoning_tokens' => $this->reasoning,
            'total_tokens' => $this->total(),
        ];
    }
}
