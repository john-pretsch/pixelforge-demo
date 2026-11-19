<?php

namespace App\Enums;

enum CreditTransactionType: string
{
    /** Fake purchase: adds credits. */
    case Purchase = 'purchase';
    /** Reserves the estimate's upper bound when a generation is created. */
    case Hold = 'hold';
    /** Returns the hold when the generation reaches a terminal state. */
    case Release = 'release';
    /** The actual price, on success only. */
    case Debit = 'debit';
}
