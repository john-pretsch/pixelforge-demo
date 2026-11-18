<?php

namespace App\Actions\Generations;

use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Pixelforge\PixelforgeClient;
use App\Pricing\CostEstimator;
use App\Pricing\Estimate;
use App\Pricing\Money;
use Illuminate\Support\Str;

/**
 * Saves the generation locally as `pending` (with its estimate and the markup
 * in force), then queues the submission. The row exists before Pixelforge is
 * called, so an early webhook always has something to match.
 */
class CreateGeneration
{
    public function __construct(private readonly PixelforgeClient $client) {}

    /**
     * @param  array{prompt: string, size: string, quality: string, reference_images?: list<string>|null}  $input
     */
    public function handle(User $user, array $input): Generation
    {
        $referenceImages = $input['reference_images'] ?? [];
        $estimate = $this->estimate($input['prompt'], $input['size'], $input['quality'], count($referenceImages));

        $generation = Generation::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $user->id,
            'status' => GenerationStatus::Pending,
            'prompt' => $input['prompt'],
            'size' => $input['size'],
            'quality' => $input['quality'],
            'reference_images' => $referenceImages,
            'markup' => config('pixelforge.markup'),
            'estimated_price_min' => Money::toStorage($estimate->priceMin),
            'estimated_price_max' => Money::toStorage($estimate->priceMax),
        ]);

        SubmitGeneration::dispatch($generation->id)->afterCommit();

        return $generation;
    }

    public function estimate(string $prompt, string $size, string $quality, int $referenceImageCount): Estimate
    {
        return CostEstimator::estimate(
            $this->client->pricing(),
            (string) config('pixelforge.markup'),
            $prompt,
            $size,
            $quality,
            $referenceImageCount,
        );
    }
}
