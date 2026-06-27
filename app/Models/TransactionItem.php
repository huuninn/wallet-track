<?php

declare(strict_types=1);

namespace App\Models;

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
 * @property \Carbon\CarbonImmutable|null $created_at
 */
class TransactionItem extends Model
{
    /** @use HasFactory<\Database\Factories\TransactionItemFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'transaction_id',
        'position',
        'name',
        'qty',
        'unit_price',
        'subtotal',
    ];

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
