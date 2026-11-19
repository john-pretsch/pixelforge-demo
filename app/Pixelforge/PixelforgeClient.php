<?php

namespace App\Pixelforge;

use App\Pricing\PricingTable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * The only place that talks HTTP to Pixelforge. Every failure surfaces as a
 * PixelforgeException carrying Pixelforge's `error.code`.
 *
 * Retries are deliberately NOT done here: each retry counts against the
 * per-minute limit, so callers (queue jobs) decide when to retry.
 */
class PixelforgeClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $apiKey,
        private readonly int $timeout = 15,
        private readonly int $connectTimeout = 5,
        private readonly int $pricingCacheTtl = 600,
    ) {}

    /**
     * POST /generations. The same idempotency key + params always returns the
     * same generation, so this is safe to retry after a timeout.
     *
     * @param  array{prompt: string, size: string, quality: string, reference_images?: list<string>, metadata?: array<string, string>}  $params
     * @return array<string, mixed> The generation object.
     */
    public function createGeneration(array $params, string $idempotencyKey): array
    {
        return $this->send(fn (PendingRequest $http) => $http
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->post('generations', $params))->json();
    }

    /** @return array<string, mixed> */
    public function getGeneration(string $providerId): array
    {
        return $this->send(fn (PendingRequest $http) => $http->get('generations/'.rawurlencode($providerId)))->json();
    }

    /** @return array{data: list<array<string, mixed>>, has_more: bool, next_cursor: ?string} */
    public function listGenerations(int $limit = 20, ?string $status = null, ?string $cursor = null): array
    {
        $query = array_filter(['limit' => $limit, 'status' => $status, 'cursor' => $cursor], fn ($v) => $v !== null);

        return $this->send(fn (PendingRequest $http) => $http->get('generations', $query))->json();
    }

    /** Current rates + token constants, cached. Only used for estimates. */
    public function pricing(): PricingTable
    {
        // Cache the raw array (not the object) so a cache hit never depends on class serialisation.
        $raw = Cache::remember('pixelforge:pricing', $this->pricingCacheTtl, fn () => $this->send(
            fn (PendingRequest $http) => $http->get('pricing'),
            authenticated: false,
        )->json());

        return PricingTable::fromArray($raw);
    }

    /**
     * GET /usage: billed usage per UTC day (by completion date).
     *
     * @return array<string, mixed>
     */
    public function usage(string $startDate, string $endDate): array
    {
        return $this->send(fn (PendingRequest $http) => $http->get('usage', [
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]))->json();
    }

    /** @param callable(PendingRequest): Response $call */
    private function send(callable $call, bool $authenticated = true): Response
    {
        $http = Http::baseUrl(rtrim($this->baseUrl, '/').'/')
            ->acceptJson()
            ->asJson()
            ->timeout($this->timeout)
            ->connectTimeout($this->connectTimeout);

        if ($authenticated) {
            $http = $http->withToken((string) $this->apiKey);
        }

        try {
            $response = $call($http);
        } catch (ConnectionException $e) {
            throw PixelforgeException::connectionFailed($e);
        }

        if ($response->failed()) {
            throw PixelforgeException::fromResponse($response);
        }

        return $response;
    }
}
