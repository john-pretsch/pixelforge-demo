<?php

namespace App\Models;

use App\Enums\CreditTransactionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One append-only ledger row. Rows are written only by App\Actions\Credits\*.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $generation_id
 * @property CreditTransactionType $type
 * @property string $amount Signed exact decimal string (9dp).
 * @property string|null $description
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class CreditTransaction extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'type' => CreditTransactionType::class,
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Generation, $this> */
    public function generation(): BelongsTo
    {
        return $this->belongsTo(Generation::class);
    }
}
