<?php

namespace Tests\Feature;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\WebhookEvent;
use App\Pixelforge\WebhookSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Support\PixelforgeFixtures;
use Tests\TestCase;

class PixelforgeWebhookTest extends TestCase
{
    use RefreshDatabase;

    private Generation $generation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->generation = Generation::factory()->create([
            'status' => GenerationStatus::Queued,
            'provider_id' => PixelforgeFixtures::succeededGeneration()['id'],
        ]);
    }

    public function test_succeeded_event_records_the_exact_cost_once(): void
    {
        $this->deliver('evt_1', 'generation.succeeded', $this->remote('succeeded'))->assertOk();

        $g = $this->generation->fresh();
        $this->assertSame(GenerationStatus::Succeeded, $g->status);
        $this->assertSame('0.017070000', $g->provider_cost);
        $this->assertSame('0.021337500', $g->price);
        $this->assertSame(755, $g->usage['total_tokens']);
        $this->assertNotNull($g->image_url);
        $this->assertSame('2026-11-17 17:22:50', $g->completed_at->format('Y-m-d H:i:s'));
    }

    public function test_duplicate_event_is_processed_once(): void
    {
        $this->deliver('evt_1', 'generation.succeeded', $this->remote('succeeded'))->assertOk()->assertJson(['duplicate' => false]);
        $this->deliver('evt_1', 'generation.succeeded', $this->remote('succeeded'))->assertOk()->assertJson(['duplicate' => true]);

        $this->assertSame(1, WebhookEvent::where('event_id', 'evt_1')->count());
        $this->assertSame('0.021337500', $this->generation->fresh()->price);
    }

    public function test_late_processing_event_does_not_regress_a_succeeded_generation(): void
    {
        $this->deliver('evt_2', 'generation.succeeded', $this->remote('succeeded'))->assertOk();
        $this->deliver('evt_1', 'generation.processing', $this->remote('processing'))->assertOk();

        $g = $this->generation->fresh();
        $this->assertSame(GenerationStatus::Succeeded, $g->status);
        $this->assertSame('0.021337500', $g->price);
    }

    public function test_failed_generation_costs_nothing_and_ignores_later_events(): void
    {
        $this->deliver('evt_1', 'generation.failed', $this->remote('failed', [
            'usage' => null,
            'image_url' => null,
            'error' => ['code' => 'generation_failed', 'message' => 'The model could not produce an image.'],
        ]))->assertOk();

        $this->deliver('evt_2', 'generation.succeeded', $this->remote('succeeded'))->assertOk();

        $g = $this->generation->fresh();
        $this->assertSame(GenerationStatus::Failed, $g->status);
        $this->assertSame('0.000000000', $g->provider_cost);
        $this->assertSame('0.000000000', $g->price);
        $this->assertSame('generation_failed', $g->error_code);
        $this->assertNull($g->usage);
    }

    public function test_webhook_arriving_before_the_create_response_matches_by_local_id(): void
    {
        $pending = Generation::factory()->create(['status' => GenerationStatus::Pending, 'provider_id' => null]);

        $this->deliver('evt_9', 'generation.processing', $this->remote('processing', [
            'id' => 'gen_EARLY',
            'metadata' => ['local_id' => $pending->uuid],
        ]))->assertOk();

        $pending->refresh();
        $this->assertSame('gen_EARLY', $pending->provider_id);
        $this->assertSame(GenerationStatus::Processing, $pending->status);
    }

    public function test_cost_uses_rates_locked_on_the_generation_and_markup_locked_on_the_row(): void
    {
        $this->generation->update(['markup' => '1.5000']);

        $this->deliver('evt_1', 'generation.succeeded', $this->remote('succeeded', [
            'rates' => ['input_text_tokens' => '10.00', 'input_image_tokens' => '10.00', 'output_tokens' => '80.00', 'reasoning_tokens' => '25.00'],
        ]))->assertOk();

        // Doubled rates → 0.03414; ×1.5 → 0.05121
        $g = $this->generation->fresh();
        $this->assertSame('0.034140000', $g->provider_cost);
        $this->assertSame('0.051210000', $g->price);
    }

    public function test_malformed_usage_is_not_recorded_so_the_retry_is_processed(): void
    {
        $this->deliver('evt_1', 'generation.succeeded', $this->remote('succeeded', ['usage' => ['output_tokens' => 280]]))
            ->assertServerError();

        $this->assertSame(0, WebhookEvent::count());
        $this->assertSame(GenerationStatus::Queued, $this->generation->fresh()->status);

        // Pixelforge retries the same event id with a good payload.
        $this->deliver('evt_1', 'generation.succeeded', $this->remote('succeeded'))->assertOk()->assertJson(['duplicate' => false]);
        $this->assertSame('0.021337500', $this->generation->fresh()->price);
    }

    public function test_rejects_invalid_signatures(): void
    {
        $body = json_encode($this->event('evt_1', 'generation.succeeded', $this->remote('succeeded')));

        $this->call('POST', '/webhooks/pixelforge', server: $this->headers('t='.time().',v1=bad'), content: $body)->assertStatus(400);
        $this->call('POST', '/webhooks/pixelforge', server: $this->headers(WebhookSignature::header(time() - 600, $body, 'whsec_test_secret')), content: $body)->assertStatus(400);
        $this->call('POST', '/webhooks/pixelforge', content: $body)->assertStatus(400);

        $this->assertSame(0, WebhookEvent::count());
        $this->assertSame(GenerationStatus::Queued, $this->generation->fresh()->status);
    }

    public function test_ping_and_unknown_generations_are_acknowledged(): void
    {
        $this->deliver('evt_ping', 'ping', null)->assertOk();
        $this->deliver('evt_other', 'generation.succeeded', $this->remote('succeeded', ['id' => 'gen_NOT_OURS']))->assertOk();

        $this->assertSame(2, WebhookEvent::count());
        $this->assertSame(GenerationStatus::Queued, $this->generation->fresh()->status);
    }

    private function remote(string $status, array $overrides = []): array
    {
        return PixelforgeFixtures::succeededGeneration(['status' => $status, ...$overrides]);
    }

    private function event(string $id, string $type, ?array $object): array
    {
        return [
            'id' => $id,
            'object' => 'event',
            'type' => $type,
            'created_at' => '2026-11-17T17:22:50Z',
            'data' => $object === null ? [] : ['object' => $object],
        ];
    }

    private function headers(?string $signature): array
    {
        return array_filter([
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PIXELFORGE_SIGNATURE' => $signature,
        ]);
    }

    private function deliver(string $id, string $type, ?array $object): TestResponse
    {
        $body = json_encode($this->event($id, $type, $object));

        return $this->call('POST', '/webhooks/pixelforge',
            server: $this->headers(WebhookSignature::header(time(), $body, 'whsec_test_secret')) + [
                'HTTP_PIXELFORGE_EVENT_ID' => $id,
                'HTTP_PIXELFORGE_EVENT_TYPE' => $type,
            ],
            content: $body,
        );
    }
}
