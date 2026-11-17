<?php

namespace App\Pricing;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * USD amounts. Exact decimals end to end: stored as NUMERIC(20,9), computed
 * with BigDecimal, and only rounded when formatted for display.
 */
final class Money
{
    /** Storage scale: matches NUMERIC(20,9) and Pixelforge's /v1/usage precision. */
    public const SCALE = 9;

    public static function of(BigDecimal|string|int $amount): BigDecimal
    {
        return BigDecimal::of($amount);
    }

    public static function zero(): BigDecimal
    {
        return BigDecimal::zero();
    }

    /** Normalise to storage scale (9dp). Rounds half-up only beyond nanodollars. */
    public static function normalize(BigDecimal $amount): BigDecimal
    {
        return $amount->toScale(self::SCALE, RoundingMode::HalfUp);
    }

    /** The exact string written to the database / sent over the wire. */
    public static function toStorage(BigDecimal $amount): string
    {
        return (string) self::normalize($amount);
    }

    /**
     * Human display. Whole cents for amounts >= $0.01; four decimals for
     * sub-cent amounts so a $0.0014 image doesn't read as "$0.00".
     *
     * Pass RoundingMode::Floor / Ceiling for the low / high end of a range so
     * the displayed range always contains the true value.
     */
    public static function format(BigDecimal|string|null $amount, RoundingMode $mode = RoundingMode::HalfUp): ?string
    {
        if ($amount === null) {
            return null;
        }

        $amount = self::of($amount);

        if ($amount->isZero()) {
            return '$0.00';
        }

        $subCent = $amount->toScale(4, $mode);

        if ($subCent->isZero()) {
            return '<$0.0001';
        }

        // Sub-cent amounts keep 4dp; anything that rounds to a cent or more shows whole cents.
        return '$'.($subCent->abs()->isLessThan('0.01') ? $subCent : $amount->toScale(2, $mode));
    }
}
