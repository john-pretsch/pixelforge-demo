<?php

namespace Tests\Feature;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\WebhookEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GenerationModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_money_round_trips_through_numeric_exactly(): void
    {
        $g = Generation::factory()->create(['provider_cost' => '0.017070000', 'price' => '0.021337500'])->fresh();

        $this->assertSame('0.017070000', $g->provider_cost);
        $this->assertSame('0.021337500', $g->price);
        $this->assertSame(GenerationStatus::Pending, $g->status);
    }

    public function test_a_webhook_event_id_can_only_be_stored_once(): void
    {
        WebhookEvent::create(['event_id' => 'evt_1', 'type' => 'ping', 'payload' => [], 'processed_at' => now()]);

        $this->expectException(UniqueConstraintViolationException::class);
        WebhookEvent::create(['event_id' => 'evt_1', 'type' => 'ping', 'payload' => [], 'processed_at' => now()]);
    }

    public function test_non_terminal_scope(): void
    {
        Generation::factory()->status(GenerationStatus::Processing)->create();
        Generation::factory()->status(GenerationStatus::Succeeded)->create();
        Generation::factory()->status(GenerationStatus::Failed)->create();

        $this->assertSame(1, Generation::nonTerminal()->count());
    }
}
