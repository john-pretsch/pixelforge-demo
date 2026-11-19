<?php

namespace App\Http\Controllers;

use App\Actions\Credits\PurchaseCredits;
use App\Pricing\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CreditController extends Controller
{
    /** Fake purchase: adds one of the fixed packs. No payment is taken. */
    public function store(Request $request, PurchaseCredits $purchase): RedirectResponse
    {
        $data = $request->validate([
            'pack' => ['required', 'string', Rule::in(PurchaseCredits::packs())],
        ]);

        $transaction = $purchase->handle($request->user(), $data['pack']);

        return back()->with('success', 'Added '.Money::format($transaction->amount).' in credits.');
    }
}
