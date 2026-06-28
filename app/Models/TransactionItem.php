<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\TransactionItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $transaction_id
 * @property int $position
 * @property string $name
 * @property float|null $qty
 * @property float|null $unit_price
 * @property float|null $subtotal
 * @property CarbonImmutable|null $created_at
 */
#[Fillable(['transaction_id', 'position', 'name', 'qty', 'unit_price', 'subtotal'])]
class TransactionItem extends Model
{
    /** @use HasFactory<TransactionItemFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'transaction_id' => 'integer',
            'position' => 'integer',
            'qty' => 'float',
            'unit_price' => 'float',
            'subtotal' => 'float',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Transaction>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }
}
