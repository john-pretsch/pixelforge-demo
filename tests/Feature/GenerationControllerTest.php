<?php

namespace Tests\Feature;

use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Tests\Support\PixelforgeFixtures;
use Tests\TestCase;

class GenerationControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->withCredits()->create();
    }

    /** Pricing is faked per test so outage tests can register a different response. */
    private function fakePricing(): void
    {
        Http::fake(['https://pixelforge.test/v1/pricing' => Http::response(PixelforgeFixtures::pricing())]);
    }

    public function test_guests_are_redirected(): void
    {
        $this->get('/generations')->assertRedirect('/login');
        $this->postJson('/generations/estimate', [])->assertUnauthorized();
    }

    public function test_index_shows_only_the_users_own_generations(): void
    {
        $this->fakePricing();
        $mine = Generation::factory()->for($this->user)->create([
            'status' => GenerationStatus::Succeeded,
            'provider_id' => 'gen_A',
            'price' => '0.021337500',
            'provider_cost' => '0.017070000',
        ]);
        Generation::factory()->create(); // someone else's

        $this->actingAs($this->user)->get('/generations')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Generations/Index')
                ->has('generations', 1)
                ->where('generations.0.id', $mine->id)
                ->where('generations.0.status_label', 'Succeeded')
                ->where('generations.0.price_display', '$0.02')
                ->where('generations.0.estimate.display_min', '$0.01')
                ->where('generations.0.estimate.display_max', '$0.03')
                ->where('options.sizes', ['1024x1024', '1536x1024', '1024x1536'])
                ->where('markupPercent', '25'));
    }

    public function test_history_is_paginated_newest_first(): void
    {
        $this->fakePricing();
        $ids = Generation::factory()->for($this->user)->count(23)->create()->pluck('id')->sortDesc()->values();
        Generation::factory()->count(5)->create(); // someone else's: not counted

        $this->actingAs($this->user)->get('/generations')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('generations', 10)
                ->where('generations.0.id', $ids[0])
                ->where('pagination', ['current_page' => 1, 'last_page' => 3, 'per_page' => 10, 'total' => 23, 'from' => 1, 'to' => 10]));

        $this->actingAs($this->user)->get('/generations?page=3')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('generations', 3)
                ->where('generations.0.id', $ids[20])
                ->where('generations.2.id', $ids[22])
                ->where('pagination.current_page', 3)
                ->where('pagination.from', 21));

        $this->actingAs($this->user)->get('/generations?page=9')->assertRedirect('/generations?page=3');
    }

    public function test_empty_history_has_one_page(): void
    {
        $this->fakePricing();

        $this->actingAs($this->user)->get('/generations')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('generations', 0)
                ->where('pagination.last_page', 1)
                ->where('pagination.total', 0));
    }

    public function test_pending_generation_waiting_for_capacity_is_labelled(): void
    {
        $this->fakePricing();
        Generation::factory()->for($this->user)->create([
            'error_code' => 'concurrency_limit_exceeded',
            'error_message' => 'Pixelforge is busy. Waiting for capacity…',
        ]);

        $this->actingAs($this->user)->get('/generations')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('generations.0.status_label', 'Waiting for capacity')
                ->where('generations.0.error', null)
                ->where('generations.0.notice', 'Pixelforge is busy. Waiting for capacity…'));
    }

    public function test_estimate_returns_an_exact_range(): void
    {
        $this->fakePricing();
        $this->actingAs($this->user)->postJson('/generations/estimate', [
            'prompt' => '[succeed] a red cube on a white table',
            'size' => '1024x1024',
            'quality' => 'low',
        ])
            ->assertOk()
            ->assertJson([
                'price_min' => '0.014050000',
                'price_max' => '0.021887500',
                'display' => ['min' => '$0.01', 'max' => '$0.03'],
            ]);
    }

    public function test_estimate_includes_reference_images_and_allows_empty_prompt(): void
    {
        $this->fakePricing();
        $this->actingAs($this->user)->postJson('/generations/estimate', [
            'prompt' => '',
            'size' => '1024x1024',
            'quality' => 'low',
            'reference_images' => ['https://example.com/a.png', ''],
        ])
            ->assertOk()
            ->assertJsonPath('tokens.min.input_image_tokens', 765);
    }

    public function test_store_saves_a_pending_generation_with_its_estimate_and_queues_submission(): void
    {
        $this->fakePricing();
        Queue::fake();

        $this->actingAs($this->user)->post('/generations', [
            'title' => 'Red cube',
            'prompt' => '[succeed] a red cube on a white table',
            'size' => '1024x1024',
            'quality' => 'low',
            'reference_images' => ['https://example.com/a.png', ''],
        ])->assertRedirect('/generations');

        $g = Generation::sole();
        $this->assertSame($this->user->id, $g->user_id);
        $this->assertSame('Red cube', $g->title);
        $this->assertSame(GenerationStatus::Pending, $g->status);
        $this->assertSame(['https://example.com/a.png'], $g->reference_images);
        $this->assertSame('1.2500', $g->markup);
        $this->assertNull($g->price);
        // 765 image tokens × $10/1M = 0.00765 added to both bounds → ×1.25
        $this->assertSame('0.023612500', $g->estimated_price_min);
        $this->assertSame('0.031450000', $g->estimated_price_max);

        Queue::assertPushed(SubmitGeneration::class, fn ($job) => $job->generationId === $g->id);
    }

    public function test_missing_title_defaults_to_the_first_five_words_of_the_prompt(): void
    {
        $this->fakePricing();
        Queue::fake();

        foreach (['', '   ', null] as $blank) {
            $this->actingAs($this->user)->post('/generations', [
                'title' => $blank, 'prompt' => '  a cat  on a  red mat at dawn', 'size' => '1024x1024', 'quality' => 'low',
            ])->assertSessionHasNoErrors();
        }

        $this->assertSame(['a cat on a red'], Generation::pluck('title')->unique()->values()->all());
        $this->assertSame('a cat  on a  red mat at dawn', Generation::first()->prompt); // only trimmed (Laravel's TrimStrings), otherwise untouched
    }

    public function test_given_title_is_kept_and_is_not_part_of_the_prompt(): void
    {
        $this->fakePricing();
        Queue::fake();

        $this->actingAs($this->user)->post('/generations', [
            'title' => str_repeat('t', 25), 'prompt' => 'a cat', 'size' => '1024x1024', 'quality' => 'low',
        ])->assertSessionHasNoErrors();

        $g = Generation::sole();
        $this->assertSame(str_repeat('t', 25), $g->title);
        $this->assertSame('a cat', $g->prompt);
    }

    public function test_store_validates_input_against_pixelforge_limits(): void
    {
        $this->fakePricing();
        Queue::fake();

        $this->actingAs($this->user)->post('/generations', [
            'title' => str_repeat('t', 26),
            'prompt' => str_repeat('a', 4001),
            'size' => '512x512',
            'quality' => 'ultra',
            'reference_images' => ['ftp://x', 'https://a.test/1', 'https://a.test/2', 'https://a.test/3', 'https://a.test/4'],
        ])->assertSessionHasErrors(['title', 'prompt', 'size', 'quality', 'reference_images', 'reference_images.0']);

        $this->assertSame(0, Generation::count());
        Queue::assertNothingPushed();
    }

    public function test_pricing_outage_explains_and_creates_nothing(): void
    {
        Queue::fake();
        Http::fake(['https://pixelforge.test/v1/pricing' => Http::response(['error' => ['code' => 'overloaded', 'message' => 'busy']], 503)]);

        $this->actingAs($this->user)->from('/generations')->post('/generations', [
            'title' => 'Cat', 'prompt' => 'a cat', 'size' => '1024x1024', 'quality' => 'low',
        ])->assertRedirect('/generations')->assertSessionHas('error');

        $this->actingAs($this->user)->postJson('/generations/estimate', [
            'title' => 'Cat', 'prompt' => 'a cat', 'size' => '1024x1024', 'quality' => 'low',
        ])->assertStatus(503);

        $this->assertSame(0, Generation::count());
    }

    public function test_index_still_renders_when_pricing_is_unavailable(): void
    {
        Http::fake(['https://pixelforge.test/v1/pricing' => Http::response([], 503)]);

        $this->actingAs($this->user)->get('/generations')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('options', null));
    }
}
