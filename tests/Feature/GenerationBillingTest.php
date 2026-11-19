<?php

namespace Tests\Feature;

use App\Actions\Generations\ApplyProviderState;
use App\Enums\CreditTransactionType as Type;
use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use App\Pixelforge\PixelforgeClient;
use App\Pricing\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Support\PixelforgeFixtures;
use Tests\TestCase;

/** Credit holds at submission, settlement on the terminal transition. */
class GenerationBillingTest extends TestCase
{
    use RefreshDatabase;

    private const PROMPT = '[succeed] a red cube on a white table';

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Http::fake(['https://pixelforge.test/v1/pricing' => Http::response(PixelforgeFixtures::pricing())]);
    }

    public function test_submission_is_blocked_when_the_balance_cannot_cover_the_estimate(): void
    {
        $user = User::factory()->withCredits('0.010000000')->create();

        $this->actingAs($user)->from('/generations')->post('/generations', $this->input())
            ->assertRedirect('/generations')
            ->assertSessionHasErrors(['credits' => 'Not enough credits: this image can cost up to $0.03 and your balance is $0.01. Buy credits to continue.']);

        $this->assertSame(0, Generation::count());
        $this->assertSame('0.010000000', $this->balance($user));
        Queue::assertNothingPushed();
    }

    public function test_submission_holds_the_estimate_upper_bound(): void
    {
        $user = User::factory()->withCredits('1.00')->create();

        $g = $this->submit($user);

        $hold = CreditTransaction::where('generation_id', $g->id)->sole();
        $this->assertSame(Type::Hold, $hold->type);
        $this->assertSame('-'.$g->estimated_price_max, $hold->amount);
        $this->assertSame(Money::toStorage(Money::of('1')->minus($g->estimated_price_max)), $this->balance($user));
        Queue::assertPushed(SubmitGeneration::class);
    }

    public function test_an_exact_balance_is_enough(): void
    {
        $user = User::factory()->withCredits('1.00')->create();
        $max = $this->submit($user)->estimated_price_max;

        $exact = User::factory()->withCredits($max)->create();
        $this->submit($exact);

        $this->assertSame('0.000000000', $this->balance($exact));
    }

    public function test_success_releases_the_hold_and_debits_exactly_the_price(): void
    {
        $user = User::factory()->withCredits('1.00')->create();
        $g = $this->submit($user);

        app(ApplyProviderState::class)->handle($g, PixelforgeFixtures::succeededGeneration());

        $this->assertSame('0.021337500', $g->fresh()->price);
        $this->assertSame('0.978662500', $this->balance($user));
        $this->assertEqualsCanonicalizing(['hold', 'release', 'debit'], $this->types($g));
    }

    public function test_settlement_happens_once_despite_duplicate_and_late_events(): void
    {
        $user = User::factory()->withCredits('1.00')->create();
        $g = $this->submit($user);
        $apply = app(ApplyProviderState::class);

        $apply->handle($g, PixelforgeFixtures::succeededGeneration());
        $apply->handle($g, PixelforgeFixtures::succeededGeneration());
        $apply->handle($g, PixelforgeFixtures::succeededGeneration(['status' => 'processing']));
        $apply->fail($g, 'submission_failed', 'late failure');

        $this->assertSame(3, CreditTransaction::where('generation_id', $g->id)->count());
        $this->assertSame('0.978662500', $this->balance($user));
    }

    public function test_failure_during_processing_releases_the_hold(): void
    {
        $user = User::factory()->withCredits('1.00')->create();
        $g = $this->submit($user);

        app(ApplyProviderState::class)->handle($g, PixelforgeFixtures::succeededGeneration([
            'status' => 'failed',
            'error' => ['code' => 'generation_failed', 'message' => 'Could not render.'],
        ]));

        $this->assertSame('1.000000000', $this->balance($user));
        $this->assertEqualsCanonicalizing(['hold', 'release'], $this->types($g));
    }

    public function test_rejection_at_submission_releases_the_hold(): void
    {
        $user = User::factory()->withCredits('1.00')->create();
        $g = $this->submit($user);

        Http::fake(['https://pixelforge.test/v1/generations' => Http::response(
            ['error' => ['type' => 'invalid_request_error', 'code' => 'content_policy_violation', 'message' => 'Blocked.']],
            400,
        )]);
        (new SubmitGeneration($g->id))->handle(app(PixelforgeClient::class), app(ApplyProviderState::class));

        $this->assertSame(GenerationStatus::Failed, $g->fresh()->status);
        $this->assertSame('1.000000000', $this->balance($user));
    }

    public function test_exhausted_retries_release_the_hold(): void
    {
        $user = User::factory()->withCredits('1.00')->create();
        $g = $this->submit($user);

        (new SubmitGeneration($g->id))->failed(new RuntimeException('timeout'));

        $this->assertSame(GenerationStatus::Failed, $g->fresh()->status);
        $this->assertSame('1.000000000', $this->balance($user));
    }

    public function test_generations_created_before_balances_settle_without_ledger_rows(): void
    {
        $legacy = Generation::factory()->status(GenerationStatus::Queued)->create();

        app(ApplyProviderState::class)->handle($legacy, PixelforgeFixtures::succeededGeneration());

        $this->assertSame(GenerationStatus::Succeeded, $legacy->fresh()->status);
        $this->assertSame(0, CreditTransaction::count());
    }

    public function test_balance_is_shared_with_every_page(): void
    {
        $user = User::factory()->withCredits('5.00')->create();

        $this->actingAs($user)->get('/generations')
            ->assertInertia(fn ($page) => $page
                ->where('credits.balance', '5.000000000')
                ->where('credits.display', '$5.00')
                ->where('credits.packs', ['5.00', '10.00', '25.00']));
    }

    private function submit(User $user): Generation
    {
        $this->actingAs($user)->post('/generations', $this->input())->assertSessionHasNoErrors();

        return Generation::where('user_id', $user->id)->latest('id')->firstOrFail();
    }

    /** @return array<string, string> */
    private function input(): array
    {
        return ['prompt' => self::PROMPT, 'size' => '1024x1024', 'quality' => 'low'];
    }

    private function balance(User $user): string
    {
        return Money::toStorage($user->balance());
    }

    /** @return list<string> */
    private function types(Generation $g): array
    {
        return CreditTransaction::where('generation_id', $g->id)->get()->map(fn ($t) => $t->type->value)->all();
    }
}
