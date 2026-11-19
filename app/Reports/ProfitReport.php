<?php

namespace App\Reports;

use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PixelforgeException;
use App\Pricing\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Operator report: what users were charged, what Pixelforge charged us, and
 * the profit, per UTC day and per user.
 *
 * Days are the UTC date of `completed_at`, the same bucketing Pixelforge uses
 * for GET /v1/usage, so our provider cost per day can be compared exactly
 * with its `cost_usd`. Only succeeded generations are billed (failures are $0
 * on both sides). All sums are exact NUMERIC → BigDecimal; nothing is a float.
 */
class ProfitReport
{
    public function __construct(private readonly PixelforgeClient $client) {}

    /** @return array<string, mixed> */
    public function build(Carbon $from, Carbon $to): array
    {
        $fromDate = $from->toDateString();
        $toDate = $to->toDateString();

        $rows = DB::table('generations')
            ->join('users', 'users.id', '=', 'generations.user_id')
            ->where('generations.status', 'succeeded')
            ->where('generations.completed_at', '>=', $from->copy()->startOfDay())
            ->where('generations.completed_at', '<', $to->copy()->addDay()->startOfDay())
            // completed_at is stored in UTC (app and DB timezone are UTC).
            ->selectRaw('generations.completed_at::date as day, users.id as user_id, users.name, users.email')
            ->selectRaw('count(*) as generations, sum(generations.price) as charged, sum(generations.provider_cost) as provider_cost')
            ->groupBy('day', 'users.id', 'users.name', 'users.email')
            ->orderByDesc('day')
            ->orderBy('users.name')
            ->get();

        $usage = $this->usage($fromDate, $toDate);

        /** @var array<string, array{generations: int, charged: BigDecimal, cost: BigDecimal, users: list<array<string, mixed>>}> $days */
        $days = [];
        /** @var array<int, array{name: string, email: string, generations: int, charged: BigDecimal, cost: BigDecimal}> $users */
        $users = [];

        foreach ($rows as $row) {
            $day = (string) $row->day;
            $charged = Money::of((string) $row->charged);
            $cost = Money::of((string) $row->provider_cost);
            $count = (int) $row->generations;

            $days[$day] ??= ['generations' => 0, 'charged' => Money::zero(), 'cost' => Money::zero(), 'users' => []];
            $days[$day]['generations'] += $count;
            $days[$day]['charged'] = $days[$day]['charged']->plus($charged);
            $days[$day]['cost'] = $days[$day]['cost']->plus($cost);
            $days[$day]['users'][] = [
                'user_id' => (int) $row->user_id,
                'name' => (string) $row->name,
                'email' => (string) $row->email,
                ...self::figures($count, $charged, $cost),
            ];

            $users[(int) $row->user_id] ??= ['name' => (string) $row->name, 'email' => (string) $row->email, 'generations' => 0, 'charged' => Money::zero(), 'cost' => Money::zero()];
            $users[(int) $row->user_id]['generations'] += $count;
            $users[(int) $row->user_id]['charged'] = $users[(int) $row->user_id]['charged']->plus($charged);
            $users[(int) $row->user_id]['cost'] = $users[(int) $row->user_id]['cost']->plus($cost);
        }

        // Include days Pixelforge billed that we have nothing for: that's a mismatch worth seeing.
        foreach ($usage ?? [] as $date => $remote) {
            if ($remote['generations'] > 0 || ! BigDecimal::of($remote['cost_usd'])->isZero()) {
                $days[$date] ??= ['generations' => 0, 'charged' => Money::zero(), 'cost' => Money::zero(), 'users' => []];
            }
        }
        krsort($days);

        $total = ['generations' => 0, 'charged' => Money::zero(), 'cost' => Money::zero()];
        $remoteTotal = ['generations' => 0, 'cost' => Money::zero()];
        $dayRows = [];

        foreach ($days as $date => $day) {
            $total['generations'] += $day['generations'];
            $total['charged'] = $total['charged']->plus($day['charged']);
            $total['cost'] = $total['cost']->plus($day['cost']);

            $remote = $usage[$date] ?? null;
            if ($remote !== null) {
                $remoteTotal['generations'] += $remote['generations'];
                $remoteTotal['cost'] = $remoteTotal['cost']->plus($remote['cost_usd']);
            }

            $dayRows[] = [
                'date' => $date,
                ...self::figures($day['generations'], $day['charged'], $day['cost']),
                'pixelforge' => $usage === null ? null : self::reconcile($day['generations'], $day['cost'], $remote),
                'users' => $day['users'],
            ];
        }

        $userRows = collect($users)
            ->map(fn (array $u, int $id) => ['user_id' => $id, 'name' => $u['name'], 'email' => $u['email'], ...self::figures($u['generations'], $u['charged'], $u['cost'])])
            ->sortByDesc(fn (array $u) => $u['charged']['value'])
            ->values()
            ->all();

        return [
            'from' => $fromDate,
            'to' => $toDate,
            'totals' => [
                ...self::figures($total['generations'], $total['charged'], $total['cost']),
                'pixelforge' => $usage === null ? null : self::reconcile($total['generations'], $total['cost'], [
                    'generations' => $remoteTotal['generations'],
                    'cost_usd' => (string) $remoteTotal['cost'],
                ]),
            ],
            'days' => $dayRows,
            'users' => $userRows,
            'usage_available' => $usage !== null,
        ];
    }

    /**
     * GET /v1/usage keyed by date, or null if Pixelforge is unreachable.
     * Cached briefly so refreshing the report doesn't spend the 60 req/min budget.
     *
     * @return array<string, array{generations: int, cost_usd: string}>|null
     */
    private function usage(string $from, string $to): ?array
    {
        try {
            return Cache::remember("pixelforge:usage:{$from}:{$to}", 60, function () use ($from, $to) {
                $byDate = [];
                foreach ($this->client->usage($from, $to)['data'] ?? [] as $day) {
                    $byDate[(string) $day['date']] = [
                        'generations' => (int) $day['generations'],
                        'cost_usd' => (string) $day['cost_usd'],
                    ];
                }

                return $byDate;
            });
        } catch (PixelforgeException) {
            return null;
        }
    }

    /**
     * @param  array{generations: int, cost_usd: string}|null  $remote
     * @return array<string, mixed>
     */
    private static function reconcile(int $generations, BigDecimal $cost, ?array $remote): array
    {
        $remoteCost = Money::of($remote['cost_usd'] ?? '0');
        $remoteCount = $remote['generations'] ?? 0;
        $difference = $cost->minus($remoteCost);

        return [
            'generations' => $remoteCount,
            'cost' => self::amount($remoteCost),
            'difference' => self::amount($difference),
            // Exact match at 9dp, and the same number of billed generations.
            'matches' => $difference->isZero() && $remoteCount === $generations,
        ];
    }

    /** @return array<string, mixed> */
    private static function figures(int $generations, BigDecimal $charged, BigDecimal $cost): array
    {
        $profit = $charged->minus($cost);

        return [
            'generations' => $generations,
            'charged' => self::amount($charged),
            'provider_cost' => self::amount($cost),
            'profit' => self::amount($profit),
            'margin_percent' => $charged->isZero() ? null : (string) $profit->multipliedBy(100)->dividedBy($charged, 1, RoundingMode::HalfUp),
        ];
    }

    /** @return array{value: string, display: string|null} */
    private static function amount(BigDecimal $amount): array
    {
        return [
            'value' => Money::toStorage($amount),
            'display' => $amount->isNegative() ? '−'.Money::format($amount->abs()) : Money::format($amount),
        ];
    }
}
