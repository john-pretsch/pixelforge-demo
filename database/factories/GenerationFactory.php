<?php

namespace Database\Factories;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Generation> */
class GenerationFactory extends Factory
{
    protected $model = Generation::class;

    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'user_id' => User::factory(),
            'status' => GenerationStatus::Pending,
            'title' => 'Red cube',
            'prompt' => '[succeed] a red cube on a white table',
            'size' => '1024x1024',
            'quality' => 'low',
            'reference_images' => [],
            'markup' => '1.25',
            'estimated_price_min' => '0.014050000',
            'estimated_price_max' => '0.021900000',
        ];
    }

    public function status(GenerationStatus $status): static
    {
        return $this->state(fn () => [
            'status' => $status,
            'provider_id' => $status === GenerationStatus::Pending ? null : 'gen_'.Str::ulid(),
        ]);
    }
}
