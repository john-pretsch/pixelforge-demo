<?php

namespace App\Http\Resources;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Pricing\Money;
use Brick\Math\RoundingMode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Generation */
class GenerationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $status = $this->status;
        // Pending with an error code = rate/concurrency limited, waiting to retry.
        $waiting = $status === GenerationStatus::Pending && $this->error_code !== null;

        return [
            'id' => $this->id,
            'status' => $status->value,
            'status_label' => match ($status) {
                GenerationStatus::Pending => $waiting ? 'Waiting for capacity' : 'Submitting',
                GenerationStatus::Queued => 'Queued',
                GenerationStatus::Processing => 'Processing',
                GenerationStatus::Succeeded => 'Succeeded',
                GenerationStatus::Failed => 'Failed',
            },
            'is_terminal' => $status->isTerminal(),
            'title' => $this->title,
            'prompt' => $this->prompt,
            'size' => $this->size,
            'quality' => $this->quality,
            'reference_images' => $this->reference_images ?? [],
            'estimate' => [
                'min' => $this->estimated_price_min,
                'max' => $this->estimated_price_max,
                'display_min' => Money::format($this->estimated_price_min, RoundingMode::Floor),
                'display_max' => Money::format($this->estimated_price_max, RoundingMode::Ceiling),
            ],
            // Final charge: set once on success, $0 on failure, null while in flight.
            'price' => $this->price,
            'price_display' => Money::format($this->price),
            'usage' => $this->usage,
            'notice' => $waiting ? $this->error_message : null,
            'error' => $status === GenerationStatus::Failed
                ? ['code' => $this->error_code, 'message' => $this->error_message]
                : null,
            'can_retry' => $this->canRetry(),
            'image_url' => $this->image_url,
            'image_expired' => $this->image_url_expires_at?->isPast() ?? false,
            'image_url_expires_at' => $this->image_url_expires_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
        ];
    }
}
