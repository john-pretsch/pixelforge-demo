<?php

namespace Tests\Integration;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Pixelforge\PixelforgeClient;
use Dotenv\Dotenv;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Base for the opt-in suite that talks to the REAL Pixelforge API.
 *
 * Run: composer test:integration (excluded from the default `php artisan test`).
 * Key: PIXELFORGE_LIVE_API_KEY, else PIXELFORGE_API_KEY from .env.e2e. Always a
 * DEV key: limits (60/min, 3 concurrent, 500/day) are shared with prod.
 * Without a key every test is skipped.
 */
#[Group('integration')]
abstract class LiveTestCase extends TestCase
{
    use RefreshDatabase;

    protected const BASE_URL = 'https://pixelforge.promptedit.dev/v1';

    /** Poll interval: one GET per cycle keeps us far below 60 requests/min. */
    protected const POLL_SECONDS = 3;

    protected PixelforgeClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        $key = self::liveApiKey();
        if ($key === null) {
            $this->markTestSkipped('No dev Pixelforge key: set PIXELFORGE_LIVE_API_KEY or PIXELFORGE_API_KEY in .env.e2e.');
        }

        Http::allowStrayRequests();
        config(['pixelforge.api_key' => $key, 'pixelforge.base_url' => self::BASE_URL]);

        // Rebuild the singleton with the live config.
        $this->app->forgetInstance(PixelforgeClient::class);
        $this->client = $this->app->make(PixelforgeClient::class);
    }

    protected static function liveApiKey(): ?string
    {
        $key = getenv('PIXELFORGE_LIVE_API_KEY') ?: null;

        $envFile = dirname(__DIR__, 2).'/.env.e2e';
        if ($key === null && is_readable($envFile)) {
            $key = Dotenv::parse((string) file_get_contents($envFile))['PIXELFORGE_API_KEY'] ?? null;
        }

        return is_string($key) && str_starts_with($key, 'pf_') ? $key : null;
    }

    /** Drive the real polling fallback until the row is terminal. */
    protected function waitForTerminal(Generation $generation, int $timeoutSeconds = 150): Generation
    {
        $deadline = time() + $timeoutSeconds;

        while (time() < $deadline) {
            $generation->refresh();
            if ($generation->status->isTerminal()) {
                return $generation;
            }

            sleep(self::POLL_SECONDS);
            Artisan::call('pixelforge:reconcile', ['--stale' => 0]);
        }

        $this->fail("Generation {$generation->provider_id} still {$generation->status->value} after {$timeoutSeconds}s");
    }

    /** @return array{generations: int, cost_usd: string} Today's (UTC) billed usage. */
    protected function usageToday(): array
    {
        $today = now('UTC')->toDateString();
        $day = collect($this->client->usage($today, $today)['data'])->firstWhere('date', $today);

        return ['generations' => (int) ($day['generations'] ?? 0), 'cost_usd' => (string) ($day['cost_usd'] ?? '0')];
    }

    protected function assertStatus(GenerationStatus $expected, Generation $generation): void
    {
        $this->assertSame($expected, $generation->status, "error: {$generation->error_code} {$generation->error_message}");
    }
}
