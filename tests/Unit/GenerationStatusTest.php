<?php

namespace Tests\Unit;

use App\Enums\GenerationStatus as S;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class GenerationStatusTest extends TestCase
{
    public static function transitions(): array
    {
        return [
            'pending → queued' => [S::Pending, S::Queued, true],
            'pending → failed (rejected at submit)' => [S::Pending, S::Failed, true],
            'queued → processing' => [S::Queued, S::Processing, true],
            'queued → succeeded (missed processing event)' => [S::Queued, S::Succeeded, true],
            'processing → succeeded' => [S::Processing, S::Succeeded, true],
            'processing → queued (out of order)' => [S::Processing, S::Queued, false],
            'processing → processing (duplicate)' => [S::Processing, S::Processing, false],
            'succeeded → processing (late event)' => [S::Succeeded, S::Processing, false],
            'succeeded → failed' => [S::Succeeded, S::Failed, false],
            'failed → succeeded' => [S::Failed, S::Succeeded, false],
            'succeeded → succeeded (duplicate)' => [S::Succeeded, S::Succeeded, false],
        ];
    }

    #[DataProvider('transitions')]
    public function test_status_only_moves_forward(S $from, S $to, bool $allowed): void
    {
        $this->assertSame($allowed, $from->canTransitionTo($to));
    }
}
