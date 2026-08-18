<?php

declare(strict_types=1);

namespace Dana\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Every role lives in this table: superadmin, admin, teacher, student.
 *
 * Two invariants are enforced by the database, not here, and must not be
 * re-implemented in application code (see 001_initial_schema.sql):
 *
 *  - chk_users_classroom: only a student may carry a classroom_id.
 *    A teacher owns classrooms through classrooms.teacher_id and may own
 *    several (FR-1.15); a student belongs to exactly one (FR-1.13).
 *  - chk_users_password_ct: only students and teachers may hold
 *    reversible ciphertext (FR-13.18, migration 010). Admin and
 *    superadmin passwords stay hash-only, reset-only.
 */
final class User extends Model
{
    public const ROLE_SUPERADMIN = 'superadmin';
    public const ROLE_ADMIN      = 'admin';
    public const ROLE_TEACHER    = 'teacher';
    public const ROLE_STUDENT    = 'student';

    protected $table = 'users';

    protected $fillable = [
        'role', 'center_id', 'classroom_id', 'login', 'password_hash',
        'password_ct', 'password_iv', 'password_tag', 'password_set_at',
        'full_name', 'interface_lang', 'is_active', 'created_by', 'last_login_at',
    ];

    /**
     * Credential material must never reach a JSON response. The reveal
     * endpoint (FR-1.10) returns a decrypted value explicitly and is
     * audited; nothing else may leak it by accident.
     */
    protected $hidden = [
        'password_hash', 'password_ct', 'password_iv', 'password_tag',
    ];

    protected $casts = [
        'is_active'       => 'boolean',
        'last_login_at'   => 'datetime',
        'password_set_at' => 'datetime',
    ];

    public function center(): BelongsTo
    {
        return $this->belongsTo(Center::class);
    }

    /** Students only — the single classroom this account belongs to. */
    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class);
    }

    /** Teachers only — the classrooms this teacher owns (FR-1.15). */
    public function classrooms(): HasMany
    {
        return $this->hasMany(Classroom::class, 'teacher_id');
    }

    public function isSuperadmin(): bool
    {
        return $this->role === self::ROLE_SUPERADMIN;
    }

    public function isStudent(): bool
    {
        return $this->role === self::ROLE_STUDENT;
    }

    /** FR-13.18: student and teacher passwords are recoverable. */
    public function hasRecoverablePassword(): bool
    {
        return ($this->isStudent() || $this->role === self::ROLE_TEACHER)
            && $this->password_ct !== null;
    }
}
