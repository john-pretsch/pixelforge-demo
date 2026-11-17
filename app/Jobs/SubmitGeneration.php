<?php

namespace App\Jobs;

use App\Actions\Generations\ApplyProviderState;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PixelforgeException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends a pending generation to Pixelforge.
 *
 * The local UUID is the Idempotency-Key (and metadata.local_id), so every
 * retry — including after a timeout where Pixelforge did accept the request —
 * returns the same provider generation and is never billed twice.
 */
class SubmitGeneration implements ShouldQueue
{
    use Queueable;

    /** Total submission attempts, including releases for rate/concurrency limits. */
    public const MAX_ATTEMPTS = 12;

    /** Upper bound for exponential backoff when Pixelforge sends no Retry-After. */
    public const MAX_BACKOFF_SECONDS = 60;

    public int $tries = self::MAX_ATTEMPTS;

    public int $timeout = 30;

    public function __construct(public readonly int $generationId) {}

    public function handle(PixelforgeClient $client, ApplyProviderState $apply): void
    {
        $generation = Generation::find($this->generationId);

        // Gone, or already accepted (e.g. a webhook arrived first, or a duplicate job).
        if ($generation === null || $generation->status !== GenerationStatus::Pending) {
            return;
        }

        $generation->increment('submit_attempts');
        $generation->forceFill(['submitted_at' => $generation->submitted_at ?? now()])->save();

        try {
            $remote = $client->createGeneration([
                'prompt' => $generation->prompt,
                'size' => $generation->size,
                'quality' => $generation->quality,
                'reference_images' => $generation->reference_images ?? [],
                'metadata' => ['local_id' => $generation->uuid],
            ], $generation->uuid);
        } catch (PixelforgeException $e) {
            $this->handleProviderError($generation, $e, $apply);

            return;
        }

        $apply->handle($generation, $remote);
    }

    private function handleProviderError(Generation $generation, PixelforgeException $e, ApplyProviderState $apply): void
    {
        if ($e->errorCode === 'idempotency_key_reused') {
            // Our keys are UUIDs bound to immutable params: this should be impossible.
            Log::error('Pixelforge idempotency key reused', ['generation' => $generation->id, 'uuid' => $generation->uuid]);
        }

        if ($e->isRetryable() && $this->attempts() < self::MAX_ATTEMPTS) {
            // Still pending: tell the user why it's waiting. Status doesn't change.
            $generation->forceFill(['error_code' => $e->errorCode, 'error_message' => $e->userMessage()])->save();

            $this->release($this->retryDelay($e));

            return;
        }

        $message = $e->isRetryable()
            ? 'Pixelforge stayed busy for too long, so this generation was cancelled. You were not charged.'
            : $e->userMessage();

        $apply->fail($generation, $e->errorCode, $message);
    }

    /** Honour Retry-After; otherwise exponential backoff with jitter, capped. */
    private function retryDelay(PixelforgeException $e): int
    {
        if ($e->retryAfter !== null) {
            return max(1, $e->retryAfter);
        }

        $backoff = min(self::MAX_BACKOFF_SECONDS, 2 ** min($this->attempts(), 6));

        return $backoff + random_int(0, 3);
    }

    /** Uncaught exception or timeout after the last attempt: fail the row, $0. */
    public function failed(?Throwable $e): void
    {
        $generation = Generation::find($this->generationId);

        // If Pixelforge already accepted it, it will finish (and bill) there:
        // leave it to webhooks / reconcile rather than failing it locally.
        if ($generation !== null && $generation->provider_id === null) {
            app(ApplyProviderState::class)->fail(
                $generation,
                'submission_failed',
                'Something went wrong submitting this generation. You were not charged.',
            );
        }
    }
}
