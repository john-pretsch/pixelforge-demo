<?php

namespace App\Enums;

enum GenerationStatus: string
{
    /** Saved locally, not yet accepted by Pixelforge (submitting or waiting for capacity). */
    case Pending = 'pending';
    case Queued = 'queued';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    /** Position in the lifecycle. Status only ever moves to a higher rank. */
    public function rank(): int
    {
        return match ($this) {
            self::Pending => 0,
            self::Queued => 1,
            self::Processing => 2,
            self::Succeeded, self::Failed => 3,
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::Succeeded || $this === self::Failed;
    }

    /**
     * Forward-only: a terminal status never changes, and stale or out-of-order
     * updates (same or lower rank) are rejected.
     */
    public function canTransitionTo(self $next): bool
    {
        return ! $this->isTerminal() && $next->rank() > $this->rank();
    }

    /** @return list<string> */
    public static function nonTerminalValues(): array
    {
        return [self::Pending->value, self::Queued->value, self::Processing->value];
    }
}
