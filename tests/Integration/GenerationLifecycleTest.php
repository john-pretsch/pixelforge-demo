<?php

namespace Tests\Integration;

use App\Actions\Generations\CreateGeneration;
use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Pricing\CostCalculator;
use App\Pricing\Money;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Group;

/**
 * Full async flow against the live API: create → submit (with idempotency key)
 * → poll via pixelforge:reconcile → terminal state and exact costing.
 * Each run makes 2 low-quality generations (about $0.03).
 */
#[Group('integration')]
class GenerationLifecycleTest extends LiveTestCase
{
    public function test_succeeded_generation_is_costed_exactly_and_reconciles_with_usage(): void
    {
        $before = $this->usageToday();

        $generation = $this->createAndSubmit('A small red cube on a white table [succeed]');
        $this->assertNotNull($generation->provider_id, 'Pixelforge did not accept the submission');

        // Replaying the same Idempotency-Key returns the same generation, not a new one.
        $replay = $this->client->createGeneration([
            'prompt' => $generation->prompt,
            'size' => $generation->size,
            'quality' => $generation->quality,
            'reference_images' => [],
            'metadata' => ['local_id' => $generation->uuid],
        ], $generation->uuid);
        $this->assertSame($generation->provider_id, $replay['id']);

        $generation = $this->waitForTerminal($generation);
        $this->assertStatus(GenerationStatus::Succeeded, $generation);

        // Price = provider cost × markup, exactly, stored unrounded.
        $this->assertNotNull($generation->provider_cost);
        $this->assertNotNull($generation->price);
        $expected = CostCalculator::price(Money::of($generation->provider_cost), (string) $generation->markup);
        $this->assertTrue(Money::of($generation->price)->isEqualTo($expected));

        // Estimate shown to the user contained the real price.
        $price = Money::of($generation->price);
        $this->assertTrue($price->isGreaterThanOrEqualTo($generation->estimated_price_min), "price {$generation->price} below estimate");
        $this->assertTrue($price->isLessThanOrEqualTo($generation->estimated_price_max), "price {$generation->price} above estimate");

        $this->assertNotEmpty($generation->image_url);
        $this->assertNotEmpty($generation->usage);

        // Our stored provider cost reconciles with Pixelforge billing to 9dp.
        // Only conclusive if nothing else on the account completed in between.
        $after = $this->usageToday();
        if ($after['generations'] - $before['generations'] !== 1) {
            $this->markTestSkipped('Usage reconciliation inconclusive: other generations completed during the test.');
        }
        $this->assertTrue(
            BigDecimal::of($after['cost_usd'])->minus($before['cost_usd'])->isEqualTo($generation->provider_cost),
            "usage delta != provider_cost {$generation->provider_cost}",
        );
    }

    public function test_failed_generation_costs_nothing(): void
    {
        $generation = $this->waitForTerminal($this->createAndSubmit('A small blue sphere [fail]'));

        $this->assertStatus(GenerationStatus::Failed, $generation);
        $this->assertTrue(Money::of($generation->price ?? '0')->isZero());
        $this->assertTrue(Money::of($generation->provider_cost ?? '0')->isZero());
        $this->assertNotNull($generation->error_code);
        $this->assertNotNull($generation->error_message);
    }

    private function createAndSubmit(string $prompt): Generation
    {
        Queue::fake();

        $generation = app(CreateGeneration::class)->handle(User::factory()->create(), [
            'prompt' => $prompt,
            'size' => '1024x1024',
            'quality' => 'low',
        ]);

        // Run the real job by hand (no worker). Pixelforge returns random 503s,
        // after which the job leaves the row pending; retrying reuses the same
        // Idempotency-Key, so it can never create a second generation.
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            app()->call([new SubmitGeneration($generation->id), 'handle']);

            if ($generation->refresh()->status !== GenerationStatus::Pending) {
                break;
            }
            sleep(self::POLL_SECONDS);
        }

        return $generation;
    }
}
