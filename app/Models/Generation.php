<?php

namespace App\Models;

use App\Enums\GenerationStatus;
use App\Pixelforge\PixelforgeException;
use Database\Factories\GenerationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Money columns come back from Postgres as exact decimal strings and are kept
 * as strings: do arithmetic with App\Pricing\Money, never with floats.
 *
 * Status, cost, usage and image fields are only changed by
 * App\Actions\Generations\ApplyProviderState.
 *
 * @property GenerationStatus $status
 * @property string|null $title Our label only; never sent to Pixelforge.
 * @property string $estimated_price_min
 * @property string $estimated_price_max
 * @property string|null $provider_cost
 * @property string|null $price
 * @property list<string>|null $reference_images
 * @property array<string, int>|null $usage
 * @property array<string, string>|null $rates
 * @property Carbon|null $image_url_expires_at
 * @property Carbon|null $submitted_at
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $last_synced_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Generation extends Model
{
    /** @use HasFactory<GenerationFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => GenerationStatus::class,
            'reference_images' => 'array',
            'usage' => 'array',
            'rates' => 'array',
            'image_url_expires_at' => 'datetime',
            'submitted_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Find the local row for a Pixelforge generation object: by provider id,
     * or by metadata.local_id when the webhook beat the create response.
     *
     * @param  array<string, mixed>  $remote
     */
    public static function forProviderPayload(array $remote): ?self
    {
        if (is_string($remote['id'] ?? null) && ($found = self::where('provider_id', $remote['id'])->first())) {
            return $found;
        }

        $localId = $remote['metadata']['local_id'] ?? null;

        return is_string($localId) && Str::isUuid($localId)
            ? self::where('uuid', $localId)->first()
            : null;
    }

    /**
     * Failed only because Pixelforge stayed busy (overloaded / rate or
     * concurrency limited) until submission gave up. The user can retry it,
     * which creates a NEW generation: a failed row never changes.
     */
    public function canRetry(): bool
    {
        return $this->status === GenerationStatus::Failed && PixelforgeException::isRetryableCode($this->error_code);
    }

    /** Max title length (matches the column and the form). */
    public const MAX_TITLE = 25;

    /**
     * Default title when the user gives none: the prompt's first five words,
     * cut to fit the 25-character column with an ellipsis.
     */
    public static function titleFromPrompt(string $prompt): string
    {
        $words = preg_split('/\s+/u', trim($prompt), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $title = implode(' ', array_slice($words, 0, 5));

        return mb_strlen($title) > self::MAX_TITLE
            ? rtrim(mb_substr($title, 0, self::MAX_TITLE - 1)).'…'
            : $title;
    }

    /** @param Builder<self> $query */
    public function scopeNonTerminal(Builder $query): void
    {
        $query->whereIn('status', GenerationStatus::nonTerminalValues());
    }
}
