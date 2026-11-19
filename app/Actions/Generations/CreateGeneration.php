<?php

namespace App\Actions\Generations;

use App\Actions\Credits\InsufficientCredits;
use App\Actions\Credits\Ledger;
use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Pixelforge\PixelforgeClient;
use App\Pricing\CostEstimator;
use App\Pricing\Estimate;
use App\Pricing\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Saves the generation locally as `pending` (with its estimate and the markup
 * in force) and holds the estimate's upper bound from the user's credits,
 * then queues the submission. Throws InsufficientCredits if they can't afford it. The row exists before Pixelforge is
 * called, so an early webhook always has something to match.
 */
class CreateGeneration
{
    public function __construct(private readonly PixelforgeClient $client) {}

    /**
     * @param  array{title?: string|null, prompt: string, size: string, quality: string, reference_images?: list<string>|null}  $input
     *
     * @throws InsufficientCredits
     */
    public function handle(User $user, array $input): Generation
    {
        $referenceImages = $input['reference_images'] ?? [];
        $estimate = $this->estimate($input['prompt'], $input['size'], $input['quality'], count($referenceImages));

        // Lock the user row so concurrent submissions can't spend the same balance.
        $generation = DB::transaction(function () use ($user, $input, $referenceImages, $estimate) {
            User::query()->lockForUpdate()->findOrFail($user->id);

            $balance = $user->balance();
            if ($balance->isLessThan($estimate->priceMax)) {
                throw new InsufficientCredits($estimate->priceMax, $balance);
            }

            $generation = Generation::create([
                'uuid' => (string) Str::uuid(),
                'user_id' => $user->id,
                'status' => GenerationStatus::Pending,
                // No title given: default to the prompt's first five words.
                'title' => filled($input['title'] ?? null) ? $input['title'] : Generation::titleFromPrompt($input['prompt']),
                'prompt' => $input['prompt'],
                'size' => $input['size'],
                'quality' => $input['quality'],
                'reference_images' => $referenceImages,
                'markup' => config('pixelforge.markup'),
                'estimated_price_min' => Money::toStorage($estimate->priceMin),
                'estimated_price_max' => Money::toStorage($estimate->priceMax),
            ]);

            Ledger::hold($generation);

            return $generation;
        });

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
