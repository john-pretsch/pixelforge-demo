<?php

namespace App\Console\Commands;

use App\Actions\Generations\ApplyProviderState;
use App\Models\Generation;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PixelforgeException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Polling fallback: webhook delivery isn't guaranteed. Fetches generations
 * that are accepted but not terminal and haven't been updated recently, and
 * applies their state through ApplyProviderState.
 */
class ReconcileGenerations extends Command
{
    protected $signature = 'pixelforge:reconcile
        {--stale=45 : Only poll rows not updated in this many seconds}
        {--limit=15 : Max rows per run (stays well under 60 requests/min)}';

    protected $description = 'Poll Pixelforge for generations whose webhooks may have been missed';

    public function handle(PixelforgeClient $client, ApplyProviderState $apply): int
    {
        $cutoff = now()->subSeconds((int) $this->option('stale'));

        $generations = Generation::nonTerminal()
            ->whereNotNull('provider_id')
            ->where('updated_at', '<', $cutoff)
            ->where(fn ($q) => $q->whereNull('last_synced_at')->orWhere('last_synced_at', '<', $cutoff))
            ->orderBy('last_synced_at', 'asc')
            ->orderBy('id')
            ->limit((int) $this->option('limit'))
            ->get();

        $changed = 0;

        foreach ($generations as $generation) {
            try {
                $changed += (int) $apply->handle($generation, $client->getGeneration($generation->provider_id));
            } catch (PixelforgeException $e) {
                if (in_array($e->errorCode, ['rate_limit_exceeded', 'overloaded'], true)) {
                    $this->warn("Pixelforge {$e->errorCode}; stopping this run.");
                    break;
                }

                Log::warning('Reconcile failed for generation', ['generation' => $generation->id, 'code' => $e->errorCode]);
            } catch (Throwable $e) {
                report($e);
            }
        }

        $this->info("Checked {$generations->count()} generation(s), {$changed} updated.");

        return self::SUCCESS;
    }
}
