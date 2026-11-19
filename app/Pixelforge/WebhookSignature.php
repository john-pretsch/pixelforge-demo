<?php

namespace App\Pixelforge;

/**
 * Pixelforge-Signature: t=<unix timestamp>,v1=<hex HMAC-SHA256 of "<t>.<raw body>">
 */
final class WebhookSignature
{
    public static function verify(?string $header, string $rawBody, string $secret, int $now, int $tolerance = 300): bool
    {
        if ($header === null || $header === '' || $secret === '') {
            return false;
        }

        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');

            if ($key === 't' && ctype_digit($value)) {
                $timestamp = (int) $value;
            } elseif ($key === 'v1' && $value !== '') {
                $signatures[] = $value;
            }
        }

        // Reject stale (and far-future) timestamps to prevent replays.
        if ($timestamp === null || $signatures === [] || abs($now - $timestamp) > $tolerance) {
            return false;
        }

        $expected = self::sign($timestamp, $rawBody, $secret);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, strtolower($signature))) {
                return true;
            }
        }

        return false;
    }

    public static function sign(int $timestamp, string $rawBody, string $secret): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);
    }

    /** Builds a header value; used by tests. */
    public static function header(int $timestamp, string $rawBody, string $secret): string
    {
        return 't='.$timestamp.',v1='.self::sign($timestamp, $rawBody, $secret);
    }
}
