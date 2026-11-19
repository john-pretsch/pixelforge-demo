<?php

namespace App\Actions\Credits;

use App\Enums\CreditTransactionType as Type;
use App\Enums\GenerationStatus;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Pricing\Money;
use Brick\Math\BigDecimal;

/**
 * Generation side of the credit ledger. Always called inside the caller's
 * transaction, with the relevant row (user or generation) locked.
 *
 * - hold():   reserve the estimate's upper bound at creation.
 * - settle(): on the terminal transition, release the hold and, on success,
 *             debit the actual price. Unique (generation_id, type) makes
 *             settling at most once, even if called again.
 */
class Ledger
{
    public static function hold(Generation $generation): void
    {
        CreditTransaction::create([
            'user_id' => $generation->user_id,
            'generation_id' => $generation->id,
            'type' => Type::Hold,
            'amount' => Money::toStorage(Money::of($generation->estimated_price_max)->negated()),
            'description' => 'Hold for generation #'.$generation->id,
        ]);
    }

    public static function settle(Generation $generation): void
    {
        $hold = CreditTransaction::where('generation_id', $generation->id)->where('type', Type::Hold)->first();

        // Generations created before balances existed were never held: nothing to settle.
        if ($hold === null || CreditTransaction::where('generation_id', $generation->id)->where('type', Type::Release)->exists()) {
            return;
        }

        self::write($generation, Type::Release, BigDecimal::of($hold->amount)->negated(), 'Release hold for generation #'.$generation->id);

        if ($generation->status === GenerationStatus::Succeeded) {
            self::write($generation, Type::Debit, Money::of($generation->price ?? '0')->negated(), 'Generation #'.$generation->id);
        }
    }

    private static function write(Generation $generation, Type $type, BigDecimal $amount, string $description): void
    {
        CreditTransaction::create([
            'user_id' => $generation->user_id,
            'generation_id' => $generation->id,
            'type' => $type,
            'amount' => Money::toStorage($amount),
            'description' => $description,
        ]);
    }
}
