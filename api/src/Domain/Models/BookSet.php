<?php

declare(strict_types=1);

namespace Dana\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** FR-3.10: a Student's Book + Workbook pair. Classrooms target a set. */
final class BookSet extends Model
{
    protected $table = 'book_sets';

    protected $fillable = ['level_id', 'name', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function level(): BelongsTo
    {
        return $this->belongsTo(Level::class);
    }

    public function books(): HasMany
    {
        return $this->hasMany(Book::class);
    }
}
