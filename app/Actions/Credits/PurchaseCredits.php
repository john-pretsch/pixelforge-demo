<?php

namespace App\Actions\Credits;

use App\Enums\CreditTransactionType;
use App\Models\CreditTransaction;
use App\Models\User;
use App\Pricing\Money;
use InvalidArgumentException;

/** Fake purchase: no payment, just a ledger credit for one of the fixed packs. */
class PurchaseCredits
{
    /** @return list<string> */
    public static function packs(): array
    {
        return array_values(array_map('strval', (array) config('pixelforge.credit_packs')));
    }

    public function handle(User $user, string $pack): CreditTransaction
    {
        if (! in_array($pack, self::packs(), true)) {
            throw new InvalidArgumentException("Unknown credit pack: {$pack}");
        }

        return CreditTransaction::create([
            'user_id' => $user->id,
            'type' => CreditTransactionType::Purchase,
            'amount' => Money::toStorage(Money::of($pack)),
            'description' => 'Credit purchase (demo, no payment)',
        ]);
    }
}
