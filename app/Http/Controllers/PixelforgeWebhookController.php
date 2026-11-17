<?php

namespace App\Http\Controllers;

use App\Actions\Generations\ApplyProviderState;
use App\Models\Generation;
use App\Models\WebhookEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * POST /webhooks/pixelforge (signature already verified by middleware).
 *
 * Exactly-once processing: the event id is inserted into webhook_events in
 * the SAME transaction as the state change. A duplicate (even a concurrent
 * one, which blocks on the unique index) inserts nothing and is acknowledged
 * without reprocessing. If processing throws, nothing is recorded, we return
 * 500, and Pixelforge's retry is processed normally.
 */
class PixelforgeWebhookController extends Controller
{
    public function __invoke(Request $request, ApplyProviderState $apply): JsonResponse
    {
        $event = json_decode($request->getContent(), true);

        $eventId = is_array($event) && is_string($event['id'] ?? null)
            ? $event['id']
            : $request->header('Pixelforge-Event-Id');

        if (! is_array($event) || ! is_string($eventId) || $eventId === '') {
            return response()->json(['error' => 'malformed_event'], 400);
        }

        $type = (string) ($event['type'] ?? $request->header('Pixelforge-Event-Type', 'unknown'));
        $object = $event['data']['object'] ?? null;

        // Fast path for the common duplicate case.
        if (WebhookEvent::where('event_id', $eventId)->exists()) {
            return response()->json(['received' => true, 'duplicate' => true]);
        }

        $processed = DB::transaction(function () use ($eventId, $type, $object, $event, $request, $apply) {
            $inserted = DB::table('webhook_events')->insertOrIgnore([
                'event_id' => $eventId,
                'type' => $type,
                'provider_generation_id' => is_array($object) ? ($object['id'] ?? null) : null,
                'delivery_attempt' => (int) $request->header('Pixelforge-Delivery-Attempt') ?: null,
                'payload' => json_encode($event),
                'processed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($inserted === 0) {
                return false; // Concurrent duplicate already processed it.
            }

            if (str_starts_with($type, 'generation.') && is_array($object)) {
                $generation = Generation::forProviderPayload($object);

                if ($generation === null) {
                    // Not ours (another environment's generation, or created outside the app).
                    Log::info('Pixelforge webhook for unknown generation', ['event_id' => $eventId, 'generation' => $object['id'] ?? null]);
                } else {
                    $apply->handle($generation, $object);
                }
            }

            return true;
        });

        return response()->json(['received' => true, 'duplicate' => ! $processed]);
    }
}
