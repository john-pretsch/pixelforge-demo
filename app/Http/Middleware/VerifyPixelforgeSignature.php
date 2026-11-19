<?php

namespace App\Http\Middleware;

use App\Pixelforge\WebhookSignature;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/** Verifies Pixelforge-Signature against the raw body, before any JSON parsing. */
class VerifyPixelforgeSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('pixelforge.webhook_secret');

        if ($secret === '') {
            // Misconfiguration: 500 so Pixelforge keeps retrying until it's fixed.
            Log::critical('PIXELFORGE_WEBHOOK_SECRET is not set; rejecting webhook.');

            return response()->json(['error' => 'webhook_not_configured'], 500);
        }

        $valid = WebhookSignature::verify(
            header: $request->header('Pixelforge-Signature'),
            rawBody: $request->getContent(),
            secret: $secret,
            now: now()->getTimestamp(),
            tolerance: (int) config('pixelforge.signature_tolerance'),
        );

        if (! $valid) {
            Log::warning('Rejected Pixelforge webhook with invalid signature', [
                'event_id' => $request->header('Pixelforge-Event-Id'),
            ]);

            return response()->json(['error' => 'invalid_signature'], 400);
        }

        return $next($request);
    }
}
