<?php

declare(strict_types=1);

namespace Dana\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** FR-3.13: names only — Beginner…Advanced. No CEFR code. */
final class Level extends Model
{
    protected $table = 'levels';

    protected $fillable = ['name', 'slug', 'sort_order', 'is_active'];

    protected $casts = ['is_active' => 'boolean', 'sort_order' => 'integer'];

    public function units(): HasMany
    {
        return $this->hasMany(Unit::class)->orderBy('sort_order');
    }

    public function bookSets(): HasMany
    {
        return $this->hasMany(BookSet::class);
    }
}
