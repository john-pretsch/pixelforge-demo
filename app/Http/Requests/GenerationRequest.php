<?php

namespace App\Http\Requests;

use App\Models\Generation;
use App\Pixelforge\PixelforgeClient;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates generation input against Pixelforge's limits. Sizes and qualities
 * come from /v1/pricing (cached), not a hardcoded list.
 *
 * Used for both submission and live estimates; estimates allow an empty prompt.
 */
class GenerationRequest extends FormRequest
{
    public const MAX_PROMPT = 4000;

    /** Our own label for the generation; not part of the prompt, never sent to Pixelforge. */
    public const MAX_TITLE = Generation::MAX_TITLE;

    public const MAX_REFERENCE_IMAGES = 4;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        // Drop blank reference-image inputs from the form.
        $refs = $this->input('reference_images');

        if (is_array($refs)) {
            $this->merge(['reference_images' => array_values(array_filter($refs, fn ($u) => is_string($u) && trim($u) !== ''))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $pricing = app(PixelforgeClient::class)->pricing();
        $isEstimate = $this->routeIs('generations.estimate');

        return [
            // Optional: CreateGeneration defaults it to the prompt's first five words.
            'title' => ['nullable', 'string', 'max:'.self::MAX_TITLE],
            'prompt' => [$isEstimate ? 'nullable' : 'required', 'string', 'max:'.self::MAX_PROMPT],
            'size' => ['required', Rule::in($pricing->sizes())],
            'quality' => ['required', Rule::in($pricing->qualities())],
            'reference_images' => ['nullable', 'array', 'max:'.self::MAX_REFERENCE_IMAGES],
            'reference_images.*' => ['string', 'url:http,https', 'max:2048', 'distinct'],
        ];
    }

    public function messages(): array
    {
        return [
            'reference_images.max' => 'You can add up to '.self::MAX_REFERENCE_IMAGES.' reference images.',
            'reference_images.*.url' => 'Each reference image must be an http(s) URL.',
            'reference_images.*.distinct' => 'Each reference image must be different.',
        ];
    }
}
