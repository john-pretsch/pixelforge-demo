<?php

namespace Tests\Unit;

use App\Models\Generation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class GenerationTitleTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function prompts(): array
    {
        return [
            'five words' => ['A lighthouse on a cliff at dawn, oil painting', 'A lighthouse on a cliff'],
            'fewer than five' => ['Red cube', 'Red cube'],
            'collapses whitespace' => ["  a\tcat \n on   a mat today", 'a cat on a mat'],
            'keywords count as words' => ['[succeed] a paper boat on a pond', '[succeed] a paper boat on'],
            'cut to 25 with ellipsis' => ['Extraordinarily photorealistic hummingbirds hovering everywhere', 'Extraordinarily photorea…'],
            'multibyte safe' => ['ÉÉÉÉÉÉÉÉÉÉ ÉÉÉÉÉÉÉÉÉÉ ÉÉÉÉÉÉÉÉÉÉ', 'ÉÉÉÉÉÉÉÉÉÉ ÉÉÉÉÉÉÉÉÉÉ ÉÉ…'],
        ];
    }

    #[DataProvider('prompts')]
    public function test_title_from_prompt(string $prompt, string $expected): void
    {
        $title = Generation::titleFromPrompt($prompt);

        $this->assertSame($expected, $title);
        $this->assertLessThanOrEqual(Generation::MAX_TITLE, mb_strlen($title));
    }
}
