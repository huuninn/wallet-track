<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $chat_id
 * @property \Carbon\CarbonImmutable|null $date
 * @property string $description
 * @property float $amount
 * @property string $type  // 'expense' | 'income'
 * @property string|null $category
 * @property string|null $observations
 * @property string $sync_status  // 'pending' | 'synced' | 'failed'
 * @property int $sync_attempts
 * @property \Carbon\CarbonImmutable|null $sync_last_attempt_at
 * @property string|null $sync_error_message
 * @property string|null $spreadsheet_row_id
 * @property bool $processing
 * @property \Carbon\CarbonImmutable|null $processing_since
 * @property \Carbon\CarbonImmutable|null $notified_at
 * @property \Carbon\CarbonImmutable|null $created_at
 * @property \Carbon\CarbonImmutable|null $updated_at
 */
class Transaction extends Model
{
    /** @use HasFactory<\Database\Factories\TransactionFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'amount' => 'float',
            'date' => 'immutable_date',
            'processing' => 'boolean',
            'sync_attempts' => 'integer',
            'sync_last_attempt_at' => 'immutable_datetime',
            'processing_since' => 'immutable_datetime',
            'notified_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    protected $fillable = [
        'chat_id',
        'date',
        'description',
        'amount',
        'type',
        'category',
        'observations',
        'sync_status',
        'sync_attempts',
        'sync_last_attempt_at',
        'sync_error_message',
        'spreadsheet_row_id',
        'processing',
        'processing_since',
        'notified_at',
    ];

    /**
     * Items detalhados da transação (cupom fiscal), ordenados por posição.
     *
     * @return HasMany<TransactionItem>
     */
    public function items(): HasMany
    {
        return $this->hasMany(TransactionItem::class)->orderBy('position');
    }

    /**
     * Labels associadas à transação (N:N via transaction_labels).
     *
     * @return BelongsToMany<Label>
     */
    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(Label::class, 'transaction_labels');
    }
}
