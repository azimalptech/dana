<?php

declare(strict_types=1);

namespace Dana\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The CHILD UNIT of the §13 hierarchy (1A, 1B, 2A …). The table name
 * predates the redesign and is kept; only the meaning moved — content
 * no longer attaches here but to the typed `sections` rows beneath it
 * (FR-13.1/FR-13.2).
 *
 * `level_position` is the teaching order across the entire level and
 * still drives source ordering.
 */
final class UnitSection extends Model
{
    public $timestamps = false;

    protected $table = 'unit_sections';

    protected $fillable = ['unit_id', 'code', 'title', 'sort_order', 'level_position'];

    protected $casts = ['sort_order' => 'integer', 'level_position' => 'integer'];

    /** The parent unit — a container only (FR-13.1). */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /** The typed sections beneath this child unit (§13). */
    public function sections(): HasMany
    {
        return $this->hasMany(Section::class)->orderBy('sort_order');
    }

    /** Displayed label, e.g. unit 1 + code 'A' -> "1-A" (design 2026-08). */
    public function label(): string
    {
        return ($this->unit?->number ?? '?') . '-' . $this->code;
    }
}
