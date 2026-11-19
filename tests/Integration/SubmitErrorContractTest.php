<?php

namespace Tests\Integration;

use App\Pixelforge\PixelforgeException;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;

/** The error codes SubmitGeneration branches on are what the live API returns. */
#[Group('integration')]
class SubmitErrorContractTest extends LiveTestCase
{
    public function test_blocked_prompt_is_a_terminal_content_policy_error(): void
    {
        $e = $this->submitExpectingError('integration test [blocked]');

        $this->assertSame('content_policy_violation', $e->errorCode);
        $this->assertSame(400, $e->httpStatus);
        $this->assertFalse($e->isRetryable());
    }

    public function test_overloaded_prompt_is_retryable_with_retry_after(): void
    {
        $e = $this->submitExpectingError('integration test [overloaded]');

        $this->assertSame('overloaded', $e->errorCode);
        $this->assertSame(503, $e->httpStatus);
        $this->assertTrue($e->isRetryable());
        $this->assertNotNull($e->retryAfter);
    }

    public function test_invalid_size_is_a_validation_error(): void
    {
        $e = $this->submitExpectingError('integration test', ['size' => '999x999']);

        $this->assertSame('validation_failed', $e->errorCode);
        $this->assertSame(422, $e->httpStatus);
        $this->assertFalse($e->isRetryable());
    }

    /** @param array<string, string> $overrides */
    private function submitExpectingError(string $prompt, array $overrides = []): PixelforgeException
    {
        try {
            $this->client->createGeneration(
                [...['prompt' => $prompt, 'size' => '1024x1024', 'quality' => 'low'], ...$overrides],
                (string) Str::uuid(),
            );
        } catch (PixelforgeException $e) {
            return $e;
        }

        $this->fail('Expected Pixelforge to reject the request');
    }
}
