<?php

namespace App\Actions\Generations;

use App\Actions\Credits\Ledger;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Pricing\CostCalculator;
use App\Pricing\Money;
use App\Pricing\Rates;
use App\Pricing\TokenUsage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The ONLY code that changes a generation's status, cost, usage or image.
 * Webhooks, SubmitGeneration and reconciliation all go through here.
 *
 * - Runs in a transaction with the row locked, so concurrent webhooks and
 *   polls are serialised per generation.
 * - Status only moves forward; terminal rows never change. Stale, duplicate
 *   and out-of-order updates are no-ops.
 * - Actual cost is written once, on the transition into `succeeded`, from the
 *   rates locked on the provider generation. Failed generations cost $0.
 * - The credit hold is settled in the same transaction: released on any
 *   terminal state, plus a debit of the actual price on success.
 */
class ApplyProviderState
{
    /**
     * @param  array<string, mixed>  $remote  Pixelforge generation object.
     * @return bool Whether the status changed.
     */
    public function handle(Generation $generation, array $remote): bool
    {
        $next = GenerationStatus::tryFrom((string) ($remote['status'] ?? ''))
            ?? throw new InvalidArgumentException('Unknown Pixelforge status: '.json_encode($remote['status'] ?? null));

        return DB::transaction(function () use ($generation, $remote, $next) {
            /** @var Generation $row */
            $row = Generation::query()->lockForUpdate()->findOrFail($generation->getKey());

            $row->last_synced_at = now();

            // Backfill: a webhook can arrive before the create call has returned.
            if ($row->provider_id === null && is_string($remote['id'] ?? null)) {
                $row->provider_id = $remote['id'];
            }

            if (! $row->status->canTransitionTo($next)) {
                $row->save();
                $generation->setRawAttributes($row->getAttributes(), true);

                return false;
            }

            $row->status = $next;
            $row->started_at ??= self::time($remote['started_at'] ?? null);

            if (! $next->isTerminal()) {
                // Accepted by Pixelforge: clear any "waiting for capacity" notice from submission.
                $row->error_code = null;
                $row->error_message = null;
            }

            if ($next === GenerationStatus::Succeeded) {
                $this->recordSuccess($row, $remote);
            }

            if ($next === GenerationStatus::Failed) {
                $this->recordFailure(
                    $row,
                    (string) ($remote['error']['code'] ?? 'generation_failed'),
                    self::failureMessage($remote['error'] ?? null),
                    self::time($remote['completed_at'] ?? null),
                );
            }

            $row->save();
            $generation->setRawAttributes($row->getAttributes(), true);

            return true;
        });
    }

    /**
     * Fail a generation that Pixelforge never accepted (rejected at submission
     * or retries exhausted). Same locking and forward-only rules.
     */
    public function fail(Generation $generation, string $code, string $message): bool
    {
        return DB::transaction(function () use ($generation, $code, $message) {
            /** @var Generation $row */
            $row = Generation::query()->lockForUpdate()->findOrFail($generation->getKey());

            if (! $row->status->canTransitionTo(GenerationStatus::Failed)) {
                return false;
            }

            $row->status = GenerationStatus::Failed;
            $this->recordFailure($row, $code, $message, now());
            $row->save();
            $generation->setRawAttributes($row->getAttributes(), true);

            return true;
        });
    }

    /** @param array<string, mixed> $remote */
    private function recordSuccess(Generation $row, array $remote): void
    {
        // Malformed usage/rates throw: the transaction rolls back and the caller
        // (webhook → Pixelforge retry, or the next reconcile) tries again later.
        $usage = TokenUsage::fromArray((array) ($remote['usage'] ?? []));
        $rates = Rates::fromArray((array) ($remote['rates'] ?? []));

        $providerCost = CostCalculator::providerCost($usage, $rates);

        $row->usage = $usage->toArray();
        $row->rates = $remote['rates'];
        $row->provider_cost = Money::toStorage($providerCost);
        // Markup locked on the row at creation, matching the estimate the user saw.
        $row->price = Money::toStorage(CostCalculator::price($providerCost, (string) $row->markup));
        $row->image_url = $remote['image_url'] ?? null;
        $row->image_url_expires_at = self::time($remote['image_url_expires_at'] ?? null);
        $row->completed_at = self::time($remote['completed_at'] ?? null) ?? now();
        $row->error_code = null;
        $row->error_message = null;

        Ledger::settle($row); // Release the hold, debit the actual price.
    }

    private function recordFailure(Generation $row, string $code, string $message, ?Carbon $completedAt): void
    {
        $row->provider_cost = Money::toStorage(Money::zero());
        $row->price = Money::toStorage(Money::zero());
        $row->error_code = $code;
        $row->error_message = $message;
        $row->completed_at = $completedAt ?? now();

        Ledger::settle($row); // Release the hold: failures are free.
    }

    private static function failureMessage(mixed $error): string
    {
        $message = is_array($error) && is_string($error['message'] ?? null) ? $error['message'] : null;

        return match (is_array($error) ? ($error['code'] ?? null) : null) {
            'internal_error' => 'Pixelforge hit an internal error. You were not charged. Retrying usually works.',
            default => ($message ?? 'The image could not be generated.').' You were not charged.',
        };
    }

    private static function time(mixed $value): ?Carbon
    {
        return is_string($value) && $value !== '' ? Carbon::parse($value)->utc() : null;
    }
}
