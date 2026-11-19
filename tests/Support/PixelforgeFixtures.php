<?php

namespace Tests\Support;

/** Real Pixelforge payloads captured on 2026-11-17, used as test fixtures. */
final class PixelforgeFixtures
{
    /** Body of GET /v1/pricing. */
    public static function pricing(): array
    {
        return [
            'object' => 'pricing',
            'currency' => 'usd',
            'unit' => 'per_1m_tokens',
            'rates' => self::rates(),
            'tokens' => [
                'characters_per_text_token' => 4,
                'text_token_variance' => 0.15,
                'per_reference_image' => 765,
                'output' => [
                    'low' => ['1024x1024' => 280, '1536x1024' => 420, '1024x1536' => 420],
                    'medium' => ['1024x1024' => 1100, '1536x1024' => 1650, '1024x1536' => 1650],
                    'high' => ['1024x1024' => 4300, '1536x1024' => 6450, '1024x1536' => 6450],
                ],
                'reasoning' => [
                    'low' => ['min' => 0, 'max' => 500],
                    'medium' => ['min' => 200, 'max' => 2000],
                    'high' => ['min' => 1000, 'max' => 8000],
                ],
            ],
        ];
    }

    public static function rates(): array
    {
        return [
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ];
    }

    /** gen_01M7N8G29M9E60870M9QJ4H2MD. GET /v1/usage billed it at cost_usd 0.017070000. */
    public static function succeededGeneration(array $overrides = []): array
    {
        return array_replace([
            'id' => 'gen_01M7N8G29M9E60870M9QJ4H2MD',
            'object' => 'generation',
            'status' => 'succeeded',
            'prompt' => '[succeed] a red cube on a white table',
            'size' => '1024x1024',
            'quality' => 'low',
            'reference_images' => [],
            'metadata' => [],
            'image_url' => 'https://pixelforge.promptedit.dev/images/gen_01M7N8G29M9E60870M9QJ4H2MD.png?sig=test',
            'image_url_expires_at' => '2026-11-18T17:22:50Z',
            'usage' => [
                'input_text_tokens' => 9,
                'input_image_tokens' => 0,
                'output_tokens' => 280,
                'reasoning_tokens' => 466,
                'total_tokens' => 755,
            ],
            'rates' => self::rates(),
            'error' => null,
            'created_at' => '2026-11-17T17:22:26Z',
            'started_at' => '2026-11-17T17:22:27Z',
            'completed_at' => '2026-11-17T17:22:50Z',
        ], $overrides);
    }
}
