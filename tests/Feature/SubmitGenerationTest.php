<?php

namespace Tests\Feature;

use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\PixelforgeFixtures;
use Tests\TestCase;

class SubmitGenerationTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://pixelforge.test/v1/generations';

    private Generation $generation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->generation = Generation::factory()->create(['reference_images' => ['https://example.com/ref.png']]);
    }

    public function test_submits_with_idempotency_key_and_local_id_then_marks_queued(): void
    {
        Http::fake([self::URL => Http::response($this->queued(), 202)]);

        $this->run_job();

        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && $r->url() === self::URL
            && $r->header('Idempotency-Key') === [$this->generation->uuid]
            && $r->header('Authorization') === ['Bearer pf_test_key']
            && $r['metadata'] === ['local_id' => $this->generation->uuid]
            && $r['reference_images'] === ['https://example.com/ref.png']
            && $r['prompt'] === $this->generation->prompt);

        $g = $this->generation->fresh();
        $this->assertSame(GenerationStatus::Queued, $g->status);
        $this->assertSame('gen_01M7N8G29M9E60870M9QJ4H2MD', $g->provider_id);
        $this->assertSame(1, $g->submit_attempts);
        $this->assertNull($g->price);
    }

    public function test_rate_limit_releases_the_job_with_retry_after_and_stays_pending(): void
    {
        Http::fake([self::URL => $this->error(429, 'concurrency_limit_exceeded', ['Retry-After' => '7'])]);

        $job = $this->run_job();

        $job->assertReleased(7);
        $g = $this->generation->fresh();
        $this->assertSame(GenerationStatus::Pending, $g->status);
        $this->assertSame('concurrency_limit_exceeded', $g->error_code);
        $this->assertStringContainsString('Waiting for capacity', $g->error_message);
    }

    public function test_overload_without_retry_after_backs_off(): void
    {
        Http::fake([self::URL => $this->error(503, 'overloaded')]);

        $job = $this->run_job();

        $job->assertReleased();
        $this->assertSame(GenerationStatus::Pending, $this->generation->fresh()->status);
    }

    public function test_connection_errors_are_retried(): void
    {
        Http::fake([self::URL => fn () => throw new ConnectionException('timed out')]);

        $this->run_job()->assertReleased();
        $this->assertSame(GenerationStatus::Pending, $this->generation->fresh()->status);
    }

    public function test_content_policy_rejection_fails_for_free_without_retrying(): void
    {
        Http::fake([self::URL => $this->error(400, 'content_policy_violation')]);

        $job = $this->run_job();

        $job->assertNotReleased();
        $g = $this->generation->fresh();
        $this->assertSame(GenerationStatus::Failed, $g->status);
        $this->assertSame('content_policy_violation', $g->error_code);
        $this->assertSame('0.000000000', $g->price);
        $this->assertStringContainsString('not charged', $g->error_message);
    }

    public function test_daily_limit_fails_for_free(): void
    {
        Http::fake([self::URL => $this->error(429, 'daily_limit_exceeded', ['Retry-After' => '3600'])]);

        $this->run_job()->assertNotReleased();
        $this->assertSame(GenerationStatus::Failed, $this->generation->fresh()->status);
    }

    public function test_gives_up_after_max_attempts(): void
    {
        Http::fake([self::URL => $this->error(503, 'overloaded')]);

        $job = $this->run_job(attempts: SubmitGeneration::MAX_ATTEMPTS);

        $job->assertNotReleased();
        $g = $this->generation->fresh();
        $this->assertSame(GenerationStatus::Failed, $g->status);
        $this->assertSame('0.000000000', $g->price);
    }

    public function test_does_nothing_if_a_webhook_already_advanced_the_generation(): void
    {
        Http::fake();
        $this->generation->update(['status' => GenerationStatus::Processing, 'provider_id' => 'gen_X']);

        $this->run_job();

        Http::assertNothingSent();
        $this->assertSame(GenerationStatus::Processing, $this->generation->fresh()->status);
    }

    public function test_idempotent_replay_returns_existing_generation_without_regressing_it(): void
    {
        // Retry after a timeout: Pixelforge replays the original generation, which is already processing.
        Http::fake([self::URL => Http::response(
            PixelforgeFixtures::succeededGeneration(['status' => 'processing', 'usage' => null]),
            202,
            ['Idempotent-Replayed' => 'true'],
        )]);

        $this->run_job();
        $this->run_job();

        $this->assertSame(1, Generation::count());
        $this->assertSame(GenerationStatus::Processing, $this->generation->fresh()->status);
    }

    private function run_job(int $attempts = 1): SubmitGeneration
    {
        $job = (new SubmitGeneration($this->generation->id))->withFakeQueueInteractions();
        $job->job->attempts = $attempts;

        app()->call([$job, 'handle']);

        return $job;
    }

    private function queued(): array
    {
        return PixelforgeFixtures::succeededGeneration([
            'status' => 'queued',
            'usage' => null,
            'image_url' => null,
            'image_url_expires_at' => null,
            'started_at' => null,
            'completed_at' => null,
            'metadata' => ['local_id' => $this->generation->uuid],
        ]);
    }

    private function error(int $status, string $code, array $headers = [])
    {
        return Http::response(['error' => ['type' => 'error', 'code' => $code, 'message' => $code]], $status, $headers);
    }
}
