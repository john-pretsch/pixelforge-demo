<?php

namespace App\Models;

use App\Enums\GenerationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money columns come back from Postgres as exact decimal strings and are kept
 * as strings: do arithmetic with App\Pricing\Money, never with floats.
 *
 * Status, cost, usage and image fields are only changed by
 * App\Actions\Generations\ApplyProviderState.
 *
 * @property GenerationStatus $status
 * @property string $estimated_price_min
 * @property string $estimated_price_max
 * @property string|null $provider_cost
 * @property string|null $price
 */
class Generation extends Model
{
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @param Builder<self> $query */
    public function scopeNonTerminal(Builder $query): void
    {
        $query->whereIn('status', GenerationStatus::nonTerminalValues());
    }
}
