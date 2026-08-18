<?php

declare(strict_types=1);

namespace Dana\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A classroom is created by a centre admin and assigned one teacher, one
 * level and one book set (Student's Book + Workbook pair, FR-3.10).
 *
 * A teacher may own several classrooms (FR-1.15). A student belongs to
 * exactly one (FR-1.13) — a person taking two courses gets two separate
 * accounts with distinct logins (FR-1.17).
 */
final class Classroom extends Model
{
    protected $table = 'classrooms';

    protected $fillable = [
        'center_id', 'teacher_id', 'level_id', 'book_set_id',
        'name', 'started_on', 'closed_at', 'capacity', 'is_active', 'created_by',
    ];

    protected $casts = [
        'is_active'  => 'boolean',
        'started_on' => 'date',
        'closed_at'  => 'datetime',
    ];

    public function center(): BelongsTo
    {
        return $this->belongsTo(Center::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function level(): BelongsTo
    {
        return $this->belongsTo(Level::class);
    }

    public function bookSet(): BelongsTo
    {
        return $this->belongsTo(BookSet::class);
    }

    /** Students enrolled here — one account each (FR-1.13). */
    public function students(): HasMany
    {
        return $this->hasMany(User::class, 'classroom_id')
            ->where('role', User::ROLE_STUDENT);
    }

    /** FR-1.14: a closed course has had its student data purged. */
    public function isClosed(): bool
    {
        return $this->closed_at !== null;
    }
}
