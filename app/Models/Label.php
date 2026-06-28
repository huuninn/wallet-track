<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\LabelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $id
 * @property string $folded_name
 * @property string $name
 * @property int $use_count
 * @property CarbonImmutable|null $last_used_at
 * @property CarbonImmutable|null $created_at
 */
#[Fillable(['folded_name', 'name', 'use_count', 'last_used_at'])]
class Label extends Model
{
    /** @use HasFactory<LabelFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'use_count' => 'integer',
            'last_used_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsToMany<Transaction>
     */
    public function transactions(): BelongsToMany
    {
        return $this->belongsToMany(Transaction::class, 'transaction_labels');
    }
}
