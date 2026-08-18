<?php

declare(strict_types=1);

namespace Dana\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * FR-3.12: a unit is a container only. It holds no content of its own —
 * grammar, vocabulary and exercises all attach to its sections, and the
 * number of sections per unit varies.
 */
final class Unit extends Model
{
    public $timestamps = false;

    protected $table = 'units';

    protected $fillable = ['level_id', 'number', 'title', 'sort_order'];

    protected $casts = ['number' => 'integer', 'sort_order' => 'integer'];

    public function level(): BelongsTo
    {
        return $this->belongsTo(Level::class);
    }

    public function sections(): HasMany
    {
        return $this->hasMany(UnitSection::class)->orderBy('sort_order');
    }
}
