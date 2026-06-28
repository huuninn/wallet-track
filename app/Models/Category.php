<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $slug
 * @property string $display_name
 * @property string $default_type // 'expense' | 'income'
 * @property int $use_count
 * @property bool $is_default
 * @property CarbonImmutable|null $created_at
 */
#[Fillable(['slug', 'display_name', 'default_type', 'use_count', 'is_default'])]
class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'use_count' => 'integer',
            'is_default' => 'boolean',
            'created_at' => 'immutable_datetime',
        ];
    }
}
