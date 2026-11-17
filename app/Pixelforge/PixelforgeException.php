<?php

namespace App\Pixelforge;

use Illuminate\Http\Client\Response;
use RuntimeException;
use Throwable;

/**
 * A Pixelforge API error. Branch on $errorCode (Pixelforge's `error.code`),
 * never on the message text.
 */
class PixelforgeException extends RuntimeException
{
    /** Local code for timeouts / DNS / TLS failures: we never got a response. */
    public const CONNECTION_ERROR = 'connection_error';

    /** Codes worth retrying with the same Idempotency-Key. */
    private const RETRYABLE = [
        'rate_limit_exceeded',
        'concurrency_limit_exceeded',
        'overloaded',
        self::CONNECTION_ERROR,
    ];

    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly ?int $httpStatus = null,
        public readonly ?int $retryAfter = null,
        public readonly ?string $param = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function fromResponse(Response $response): self
    {
        $error = $response->json('error');
        $status = $response->status();

        // An unexpected 5xx without a Pixelforge error body is treated like an overload: retryable.
        $code = is_array($error) && is_string($error['code'] ?? null)
            ? $error['code']
            : ($status >= 500 ? 'overloaded' : 'unexpected_response');

        $retryAfter = $response->header('Retry-After');

        return new self(
            errorCode: $code,
            message: is_array($error) && is_string($error['message'] ?? null)
                ? $error['message']
                : "Pixelforge returned HTTP {$status}.",
            httpStatus: $status,
            retryAfter: is_numeric($retryAfter) ? max(0, (int) $retryAfter) : null,
            param: is_array($error) ? ($error['param'] ?? null) : null,
        );
    }

    public static function connectionFailed(Throwable $e): self
    {
        return new self(self::CONNECTION_ERROR, 'Could not reach Pixelforge: '.$e->getMessage(), previous: $e);
    }

    public function isRetryable(): bool
    {
        return in_array($this->errorCode, self::RETRYABLE, true);
    }

    /** Message safe to show the end user. */
    public function userMessage(): string
    {
        return match ($this->errorCode) {
            'content_policy_violation' => 'Your prompt was rejected by the content policy. You were not charged.',
            'validation_failed' => 'Pixelforge rejected the request'.($this->param ? " ({$this->param})" : '').'. You were not charged.',
            'daily_limit_exceeded' => 'The daily generation limit has been reached. Please try again after midnight UTC. You were not charged.',
            'rate_limit_exceeded', 'concurrency_limit_exceeded', 'overloaded', self::CONNECTION_ERROR => 'Pixelforge is busy. Waiting for capacity…',
            default => 'Something went wrong submitting this generation. You were not charged.',
        };
    }
}
