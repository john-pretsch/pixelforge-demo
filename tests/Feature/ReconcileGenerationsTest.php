<?php

namespace Tests\Feature;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\PixelforgeFixtures;
use Tests\TestCase;

class ReconcileGenerationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_polls_stale_generations_and_applies_their_state(): void
    {
        $id = PixelforgeFixtures::succeededGeneration()['id'];
        $stale = Generation::factory()->create(['status' => GenerationStatus::Processing, 'provider_id' => $id]);
        Generation::where('id', $stale->id)->update(['updated_at' => now()->subMinutes(5)]);

        $fresh = Generation::factory()->status(GenerationStatus::Processing)->create(); // updated just now: skipped
        Generation::factory()->status(GenerationStatus::Succeeded)->create();          // terminal: skipped

        Http::fake(["https://pixelforge.test/v1/generations/{$id}" => Http::response(PixelforgeFixtures::succeededGeneration())]);

        $this->artisan('pixelforge:reconcile')->assertSuccessful();

        Http::assertSentCount(1);
        $this->assertSame(GenerationStatus::Succeeded, $stale->fresh()->status);
        $this->assertSame('0.021337500', $stale->fresh()->price);
        $this->assertSame(GenerationStatus::Processing, $fresh->fresh()->status);
    }

    public function test_stops_the_run_when_rate_limited(): void
    {
        foreach (range(1, 3) as $i) {
            $g = Generation::factory()->status(GenerationStatus::Queued)->create();
            Generation::where('id', $g->id)->update(['updated_at' => now()->subMinutes(5)]);
        }

        Http::fake(['*' => Http::response(['error' => ['code' => 'rate_limit_exceeded', 'message' => 'slow down']], 429, ['Retry-After' => '10'])]);

        $this->artisan('pixelforge:reconcile')->assertSuccessful();

        Http::assertSentCount(1);
    }
}
