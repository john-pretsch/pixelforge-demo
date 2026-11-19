<?php

namespace App\Actions\Credits;

use App\Pricing\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use RuntimeException;

class InsufficientCredits extends RuntimeException
{
    public function __construct(public readonly BigDecimal $required, public readonly BigDecimal $balance)
    {
        parent::__construct(sprintf(
            'Not enough credits: this image can cost up to %s and your balance is %s. Buy credits to continue.',
            Money::format($required, RoundingMode::Ceiling),
            Money::format($balance, RoundingMode::Floor),
        ));
    }
}
