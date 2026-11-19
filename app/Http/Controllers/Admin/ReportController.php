<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Reports\ProfitReport;
use Brick\Math\BigDecimal;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class ReportController extends Controller
{
    public function index(Request $request, ProfitReport $report): Response
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $to = Carbon::parse($validated['to'] ?? now()->utc()->toDateString(), 'UTC');
        $from = Carbon::parse($validated['from'] ?? $to->copy()->subDays(29)->toDateString(), 'UTC');

        // GET /v1/usage accepts at most 92 days.
        if ($from->diffInDays($to) > 91) {
            $from = $to->copy()->subDays(91);
        }

        return Inertia::render('Admin/Report', [
            'report' => $report->build($from, $to),
            'markupPercent' => (string) BigDecimal::of(config('pixelforge.markup'))->minus(1)->multipliedBy(100)->stripTrailingZeros(),
        ]);
    }
}
