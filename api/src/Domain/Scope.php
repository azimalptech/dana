<?php

declare(strict_types=1);

namespace Dana\Domain;

use Dana\Domain\Models\User;
use Dana\Http\ApiException;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who the caller is, and what they are allowed to see.
 *
 * Scope filtering lives here and is applied at the repository layer —
 * never re-implemented per controller. A forgotten check in one endpoint
 * is the classic way one centre ends up reading another centre's data,
 * so there is exactly one place to get it right.
 */
final class Scope
{
    public const ATTRIBUTE = 'dana.scope';

    public function __construct(
        public readonly int $userId,
        public readonly string $role,
        public readonly ?int $centerId,
        public readonly ?int $classroomId,
    ) {
    }

    public function isSuperadmin(): bool
    {
        return $this->role === User::ROLE_SUPERADMIN;
    }

    public function isAdmin(): bool
    {
        return $this->role === User::ROLE_ADMIN;
    }

    public function isTeacher(): bool
    {
        return $this->role === User::ROLE_TEACHER;
    }

    public function isStudent(): bool
    {
        return $this->role === User::ROLE_STUDENT;
    }

    public function requireRole(string ...$roles): void
    {
        if (!in_array($this->role, $roles, true)) {
            throw ApiException::forbidden();
        }
    }

    /**
     * Restricts a query over users to what this caller may see.
     *
     *   superadmin -> everything
     *   admin      -> their centre
     *   teacher    -> students in classrooms they own
     *   student    -> themselves
     */
    public function applyToUsers(Builder $query): Builder
    {
        return match ($this->role) {
            User::ROLE_SUPERADMIN => $query,
            User::ROLE_ADMIN      => $query->where('center_id', $this->centerId),
            User::ROLE_TEACHER    => $query->whereIn(
                'classroom_id',
                fn ($sub) => $sub->select('id')->from('classrooms')->where('teacher_id', $this->userId)
            ),
            User::ROLE_STUDENT    => $query->where('id', $this->userId),
            default               => throw ApiException::forbidden(),
        };
    }

    /** Restricts a query over classrooms to what this caller may see. */
    public function applyToClassrooms(Builder $query): Builder
    {
        return match ($this->role) {
            User::ROLE_SUPERADMIN => $query,
            User::ROLE_ADMIN      => $query->where('center_id', $this->centerId),
            User::ROLE_TEACHER    => $query->where('teacher_id', $this->userId),
            User::ROLE_STUDENT    => $query->where('id', $this->classroomId),
            default               => throw ApiException::forbidden(),
        };
    }

    /**
     * FR-13.17: credentials are handled SOLELY by the centre admin.
     * The teacher's reveal authority is gone, and the superadmin's role
     * is centres and content, not student passwords (FR-13.11/13.12) —
     * so this is the centre admin for their own centre, and nobody else.
     */
    public function mayRevealPasswordOf(User $student): bool
    {
        if (!$student->isStudent()) {
            return false; // FR-1.11 — staff passwords go through StaffAccountService.
        }

        return $this->role === User::ROLE_ADMIN
            && $student->center_id === $this->centerId;
    }
}
