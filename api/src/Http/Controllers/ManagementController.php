<?php

declare(strict_types=1);

namespace Dana\Http\Controllers;

use Dana\Domain\Accounts\CourseClosureService;
use Dana\Domain\Accounts\StaffAccountService;
use Dana\Domain\Accounts\StudentAccountService;
use Dana\Domain\Models\Center;
use Dana\Domain\Models\Classroom;
use Dana\Domain\Models\Level;
use Dana\Domain\Models\User;
use Dana\Domain\Progress\StatsService;
use Dana\Http\ApiException;
use Illuminate\Database\Capsule\Manager as Capsule;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Backs the admin and superadmin panels.
 *
 * Every listing goes through Scope, so an admin sees exactly their own
 * centre and a superadmin sees everything, without either being decided
 * here.
 */
final class ManagementController extends Controller
{
    public function __construct(
        private readonly StaffAccountService $staff,
        private readonly StudentAccountService $students,
        private readonly CourseClosureService $closures,
        private readonly StatsService $stats,
    ) {
    }

    // ---------------------------------------------------------- centres

    public function centers(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $scope = $this->scope($request);
        $scope->requireRole(User::ROLE_SUPERADMIN, User::ROLE_ADMIN);

        // Deleted (deactivated) centres stay out of every listing.
        $query = Center::query()->where('is_active', 1);

        if ($scope->isAdmin()) {
            $query->where('id', $scope->centerId);
        }

        $centers = $query->orderBy('name')->get();

        // One grouped count instead of three queries per centre — the
        // list is the superadmin's landing page and grows with the chain.
        $counts = Capsule::table('users')
            ->whereIn('center_id', $centers->pluck('id')->all())
            ->where('is_active', 1)
            ->whereIn('role', [User::ROLE_ADMIN, User::ROLE_TEACHER, User::ROLE_STUDENT])
            ->selectRaw('center_id, role, COUNT(*) AS n')
            ->groupBy('center_id', 'role')
            ->get();

        $classrooms = Capsule::table('classrooms')
            ->whereIn('center_id', $centers->pluck('id')->all())
            ->where('is_active', 1)
            ->selectRaw('center_id, COUNT(*) AS n')
            ->groupBy('center_id')
            ->pluck('n', 'center_id');

        $by = static fn (int $centerId, string $role): int => (int) ($counts
            ->first(fn ($r) => (int) $r->center_id === $centerId && $r->role === $role)?->n ?? 0);

        return $this->json($response, [
            'centers' => $centers->map(fn (Center $c): array => [
                'id'         => (int) $c->id,
                'name'       => $c->name,
                'city'       => $c->city,
                'address'    => $c->address,
                'is_active'  => $c->is_active,
                'admins'     => $by((int) $c->id, User::ROLE_ADMIN),
                'teachers'   => $by((int) $c->id, User::ROLE_TEACHER),
                'students'   => $by((int) $c->id, User::ROLE_STUDENT),
                'classrooms' => (int) ($classrooms[$c->id] ?? 0),
            ])->all(),
        ]);
    }

    public function createCenter(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->scope($request)->requireRole(User::ROLE_SUPERADMIN);

        $body = $this->body($request);
        $name = trim((string) ($body['name'] ?? ''));

        if ($name === '') {
            throw ApiException::validation('Merkeziň adyny giriziň.', 'Введите название центра.');
        }

        $now = date('Y-m-d H:i:s');
        $id = Capsule::table('centers')->insertGetId([
            'name'       => mb_substr($name, 0, 160),
            'city'       => mb_substr(trim((string) ($body['city'] ?? '')), 0, 120) ?: null,
            'address'    => mb_substr(trim((string) ($body['address'] ?? '')), 0, 255) ?: null,
            'is_active'  => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->json($response, ['id' => $id, 'name' => $name], 201);
    }

    /** Superadmin edits anything (client, 2026-08-13): centre details. */
    public function updateCenter(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->scope($request)->requireRole(User::ROLE_SUPERADMIN);

        $center = Center::query()->find((int) $args['id']);
        if ($center === null) {
            throw ApiException::notFound();
        }

        $body = $this->body($request);
        $name = trim((string) ($body['name'] ?? $center->name));

        if ($name === '') {
            throw ApiException::validation('Merkeziň adyny giriziň.', 'Введите название центра.');
        }

        $center->name = mb_substr($name, 0, 160);
        if (array_key_exists('city', $body)) {
            $center->city = mb_substr(trim((string) $body['city']), 0, 120) ?: null;
        }
        if (array_key_exists('address', $body)) {
            $center->address = mb_substr(trim((string) $body['address']), 0, 255) ?: null;
        }
        $center->save();

        $this->audit($request, 'center.updated', (int) $center->id);

        return $this->json($response, ['ok' => true]);
    }

    /**
     * Deleting a centre is refused while it still runs courses — FR-1.14
     * makes closing a course an explicit, name-confirmed act with a
     * progress purge, and centre deletion must not become a way around
     * it. An empty centre deletes together with its remaining staff.
     */
    public function deleteCenter(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->scope($request)->requireRole(User::ROLE_SUPERADMIN);

        $center = Center::query()->find((int) $args['id']);
        if ($center === null) {
            throw ApiException::notFound();
        }

        $active = Capsule::table('classrooms')
            ->where('center_id', $center->id)
            ->where('is_active', 1)
            ->count();

        if ($active > 0) {
            throw ApiException::validation(
                "Merkezde {$active} işjeň kurs bar — öňürti olary ýapyň.",
                "В центре есть активные курсы ({$active}) — сначала завершите их."
            );
        }

        Capsule::connection()->transaction(function () use ($center): void {
            // Remaining accounts (staff, disabled students of closed
            // courses) go inactive rather than vanishing — audit history
            // keeps pointing at something real.
            Capsule::table('users')->where('center_id', $center->id)->update(['is_active' => 0]);
            Capsule::table('classrooms')->where('center_id', $center->id)->update(['is_active' => 0]);
            $center->is_active = false;
            $center->save();
        });

        $this->audit($request, 'center.deleted', (int) $center->id);

        return $this->json($response, ['ok' => true]);
    }

    /**
     * Superadmin removes a centre admin (client, 2026-08-13). Deactivated
     * rather than erased — the audit trail references the account — and
     * every session token dies with it.
     */
    public function deleteAdmin(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->scope($request)->requireRole(User::ROLE_SUPERADMIN);

        $admin = User::query()
            ->where('id', (int) $args['id'])
            ->where('role', User::ROLE_ADMIN)
            ->first();

        if ($admin === null) {
            throw ApiException::notFound();
        }

        $admin->is_active = false;
        $admin->save();

        Capsule::table('refresh_tokens')
            ->where('user_id', $admin->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => date('Y-m-d H:i:s')]);

        $this->audit($request, 'admin.deleted', (int) $admin->id, 'user');

        return $this->json($response, ['ok' => true]);
    }

    /**
     * DELETE /manage/teachers/{id} — mirrors deleteAdmin: deactivated
     * rather than erased (the audit trail and closed classrooms keep
     * referencing the row), every session token revoked. Centre-scoped
     * exactly like createTeacher: an admin removes their own centre's
     * teachers only; the superadmin reaches anyone.
     *
     * classrooms.teacher_id is NOT NULL in the schema, so a classroom
     * cannot be left teacherless — while ACTIVE classrooms reference the
     * teacher the delete is refused, naming them. Closed classrooms keep
     * pointing at the deactivated account, same as deleteCenter leaves
     * its disabled staff.
     */
    public function deleteTeacher(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $scope = $this->scope($request);
        $scope->requireRole(User::ROLE_ADMIN, User::ROLE_SUPERADMIN);

        $teacher = $scope->applyToUsers(User::query())
            ->where('users.id', (int) $args['id'])
            ->where('role', User::ROLE_TEACHER)
            ->first();

        if ($teacher === null) {
            throw ApiException::notFound();
        }

        $classrooms = Capsule::table('classrooms')
            ->where('teacher_id', $teacher->id)
            ->where('is_active', 1)
            ->pluck('name')
            ->all();

        if ($classrooms !== []) {
            $names = implode(', ', $classrooms);
            throw ApiException::validation(
                "Bu mugallym şu synplary alyp barýar: {$names}. Öňürti synplary başga mugallyma geçiriň ýa-da ýapyň.",
                "Этот преподаватель ведёт классы: {$names}. Сначала передайте классы другому преподавателю или завершите их."
            );
        }

        // FR-15.14: a HARD delete — the row goes, not an «отключён»
        // badge (client, 2026-08-27; deactivation read as the teacher
        // still existing). Closed courses keep their history minus the
        // pointer, the students' inbox copies keep their messages minus
        // the sender — migration 014 made those columns nullable so the
        // FKs allow exactly this and nothing else.
        Capsule::connection()->transaction(function () use ($teacher): void {
            Capsule::table('classrooms')->where('teacher_id', $teacher->id)
                ->update(['teacher_id' => null]);
            Capsule::table('classrooms')->where('created_by', $teacher->id)
                ->update(['created_by' => null]);
            Capsule::table('notifications')->where('sender_id', $teacher->id)
                ->update(['sender_id' => null]);
            Capsule::table('users')->where('created_by', $teacher->id)
                ->update(['created_by' => null]);

            // refresh_tokens / device_tokens / notification_receipts
            // cascade from the row itself.
            Capsule::table('users')->where('id', $teacher->id)->delete();
        });

        $this->audit($request, 'teacher.deleted', (int) $teacher->id, 'user');

        return $this->json($response, ['ok' => true]);
    }

    /**
     * POST /manage/teachers/{id} — the centre admin edits a teacher's
     * name and phone number (FR-15.14). Password stays its own flow.
     */
    public function updateTeacher(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $scope = $this->scope($request);
        $scope->requireRole(User::ROLE_ADMIN, User::ROLE_SUPERADMIN);

        $teacher = $scope->applyToUsers(User::query())
            ->where('users.id', (int) $args['id'])
            ->where('role', User::ROLE_TEACHER)
            ->first();

        if ($teacher === null) {
            throw ApiException::notFound();
        }

        $body = $this->body($request);

        $this->staff->updateTeacher(
            $scope,
            $teacher,
            trim((string) ($body['login'] ?? '')),
            (string) ($body['full_name'] ?? ''),
        );

        $this->audit($request, 'teacher.updated', (int) $teacher->id, 'user');

        return $this->json($response, [
            'id' => (int) $teacher->id, 'login' => $teacher->login, 'full_name' => $teacher->full_name,
        ]);
    }

    /**
     * DELETE /manage/students/{id} — a HARD delete: the account and every
     * trace of its progress go in one transaction ("I want to be able to
     * delete everything" — client). Unlike course closure (FR-1.14) this
     * removes the user row itself, so it is for single mistaken or
     * departed accounts, not for ending a course.
     *
     * Most progress tables cascade from users in the DB; they are still
     * deleted explicitly so this reads as the complete list of what is
     * destroyed (same style as CourseClosureService::close).
     */
    public function deleteStudent(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $scope = $this->scope($request);
        $scope->requireRole(User::ROLE_ADMIN, User::ROLE_SUPERADMIN);

        // Scoped lookup: an admin deletes within their own centre only.
        $student = $scope->applyToUsers(User::query())
            ->where('users.id', (int) $args['id'])
            ->where('role', User::ROLE_STUDENT)
            ->first();

        if ($student === null) {
            throw ApiException::notFound();
        }

        $id = (int) $student->id;

        Capsule::connection()->transaction(function () use ($id): void {
            // attempt_answers would cascade with its attempts anyway;
            // explicit-first keeps the purge readable and order-safe.
            Capsule::table('attempt_answers')
                ->whereIn('attempt_id', fn ($q) => $q->select('id')
                    ->from('section_attempts')
                    ->where('student_id', $id))
                ->delete();
            Capsule::table('section_attempts')->where('student_id', $id)->delete();
            Capsule::table('student_section_stats')->where('student_id', $id)->delete();
            Capsule::table('student_activity_days')->where('student_id', $id)->delete();
            Capsule::table('student_bookmarks')->where('student_id', $id)->delete();
            Capsule::table('refresh_tokens')->where('user_id', $id)->delete();
            Capsule::table('device_tokens')->where('user_id', $id)->delete();
            // The push inbox references users with ON DELETE CASCADE —
            // deleted here so the list above stays complete.
            Capsule::table('notification_receipts')->where('user_id', $id)->delete();
            Capsule::table('users')->where('id', $id)->delete();
        });

        $this->audit($request, 'student.deleted', $id, 'user');

        return $this->json($response, ['ok' => true]);
    }

    /**
     * DELETE /manage/classrooms/{id} — refused while ACTIVE students are
     * enrolled: they must be deleted individually or the course closed
     * (FR-1.14) first, so a roomful of accounts can never vanish as a
     * side effect. Disabled leftovers of a closed course are detached
     * (users.classroom_id is nullable) — their accounts survive for the
     * audit trail; the classroom row and its remaining aggregate rows
     * (section_attempts / student_section_stats cascade on classroom_id)
     * do not.
     */
    public function deleteClassroom(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $scope = $this->scope($request);
        $scope->requireRole(User::ROLE_ADMIN, User::ROLE_SUPERADMIN);

        $classroom = $scope->applyToClassrooms(Classroom::query())
            ->where('id', (int) $args['id'])
            ->first();

        if ($classroom === null) {
            throw ApiException::notFound();
        }

        $active = Capsule::table('users')
            ->where('classroom_id', $classroom->id)
            ->where('role', User::ROLE_STUDENT)
            ->where('is_active', 1)
            ->count();

        if ($active > 0) {
            throw ApiException::validation(
                "Synpda {$active} işjeň okuwçy bar — öňürti okuwçylary pozuň ýa-da kursy ýapyň.",
                "В классе есть активные ученики ({$active}) — сначала удалите учеников или завершите курс."
            );
        }

        Capsule::connection()->transaction(function () use ($classroom): void {
            // users.classroom_id is RESTRICT — disabled students of a
            // closed course still point here and must be detached before
            // the row can go.
            Capsule::table('users')->where('classroom_id', $classroom->id)->update(['classroom_id' => null]);
            Capsule::table('classrooms')->where('id', $classroom->id)->delete();
        });

        $this->audit($request, 'classroom.deleted', (int) $classroom->id, 'classroom');

        return $this->json($response, ['ok' => true]);
    }

    /** One audit row per superadmin structural change (FR-1.12). */
    private function audit(
        ServerRequestInterface $request,
        string $action,
        int $entityId,
        string $entityType = 'center',
    ): void {
        Capsule::table('audit_log')->insert([
            'actor_id'    => $this->scope($request)->userId,
            'action'      => $action,
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'ip'          => $request->getServerParams()['REMOTE_ADDR'] ?? null,
            'meta'        => null,
            'created_at'  => date('Y-m-d H:i:s'),
        ]);
    }

    // ------------------------------------------------------------ staff

    public function staffList(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $scope = $this->scope($request);
        $scope->requireRole(User::ROLE_SUPERADMIN, User::ROLE_ADMIN);

        $query = User::query()->whereIn('role', [User::ROLE_ADMIN, User::ROLE_TEACHER]);

        if ($scope->isAdmin()) {
            // An admin manages their own centre's teachers only.
            $query->where('center_id', $scope->centerId)->where('role', User::ROLE_TEACHER);
        }

        return $this->json($response, [
            'staff' => $query->orderBy('full_name')->get()->map(fn (User $u): array => [
                'id'         => (int) $u->id,
                'role'       => $u->role,
                'full_name'  => $u->full_name,
                'login'      => $u->login,
                'center_id'  => $u->center_id,
                'is_active'  => $u->is_active,
                'classrooms' => $u->role === User::ROLE_TEACHER
                    ? $u->classrooms()->where('is_active', 1)->count()
                    : null,
            ])->all(),
        ]);
    }

    public function createAdmin(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $this->body($request);

        $admin = $this->staff->createAdmin(
            $this->scope($request),
            (int) ($body['center_id'] ?? 0),
            trim((string) ($body['login'] ?? '')),
            (string) ($body['password'] ?? ''),
            (string) ($body['full_name'] ?? ''),
        );

        return $this->json($response, [
            'id' => (int) $admin->id, 'login' => $admin->login, 'full_name' => $admin->full_name,
        ], 201);
    }

    public function createTeacher(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $this->body($request);

        $teacher = $this->staff->createTeacher(
            $this->scope($request),
            trim((string) ($body['login'] ?? '')),
            (string) ($body['password'] ?? ''),
            (string) ($body['full_name'] ?? ''),
        );

        return $this->json($response, [
            'id' => (int) $teacher->id, 'login' => $teacher->login, 'full_name' => $teacher->full_name,
        ], 201);
    }

    public function resetStaffPassword(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $staff = User::query()->find((int) $args['id']);

        if ($staff === null) {
            throw ApiException::notFound();
        }

        $this->staff->resetPassword(
            $this->scope($request),
            $staff,
            (string) ($this->body($request)['password'] ?? '')
        );

        return $this->json($response, ['ok' => true]);
    }

    // ------------------------------------------------------- classrooms

    /** Options needed to fill in the "create classroom" form. */
    public function options(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->scope($request)->requireRole(User::ROLE_SUPERADMIN, User::ROLE_ADMIN);

        // Books are out of the product (FR-14.5): a classroom picks a level
        // only — no book set to choose.
        return $this->json($response, [
            'levels' => Level::query()->where('is_active', 1)->orderBy('sort_order')->get()
                ->map(fn (Level $l): array => ['id' => (int) $l->id, 'name' => $l->name])->all(),
        ]);
    }

    public function createClassroom(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $scope = $this->scope($request);
        $scope->requireRole(User::ROLE_SUPERADMIN, User::ROLE_ADMIN);

        $body = $this->body($request);
        $name = trim((string) ($body['name'] ?? ''));
        $teacherId = (int) ($body['teacher_id'] ?? 0);
        $levelId = (int) ($body['level_id'] ?? 0);

        if ($name === '' || $teacherId === 0 || $levelId === 0) {
            throw ApiException::validation(
                'Ady, mugallym we dereje saýlanmaly.',
                'Укажите название, преподавателя и уровень.'
            );
        }

        $teacher = User::query()->where('id', $teacherId)->where('role', User::ROLE_TEACHER)->first();

        if ($teacher === null) {
            throw ApiException::notFound();
        }

        // FR-12.1: the classroom lands in the teacher's own centre, and an
        // admin may not staff another centre's class.
        $centerId = $scope->isAdmin() ? $scope->centerId : (int) $teacher->center_id;

        if ((int) $teacher->center_id !== $centerId) {
            throw ApiException::forbidden();
        }

        // Books are out of the product (FR-14.5): nothing attaches a book
        // set. The column stays nullable and dormant for closed classrooms.
        $now = date('Y-m-d H:i:s');
        $id = Capsule::table('classrooms')->insertGetId([
            'center_id'   => $centerId,
            'teacher_id'  => $teacherId,
            'level_id'    => $levelId,
            'book_set_id' => null,
            'name'        => mb_substr($name, 0, 120),
            'started_on'  => $body['started_on'] ?? date('Y-m-d'),
            'capacity'    => isset($body['capacity']) ? (int) $body['capacity'] : null,
            'is_active'   => 1,
            'created_by'  => $scope->userId,
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);

        return $this->json($response, ['id' => $id, 'name' => $name], 201);
    }

    /**
     * FR-1.4 (since 2026-08-07): student accounts are created by the
     * centre admin, not the teacher. The classroom lookup is scoped, so
     * an admin can only enrol into their own centre's classes.
     */
    public function createStudent(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $scope = $this->scope($request);
        $scope->requireRole(User::ROLE_ADMIN);

        $classroom = $scope->applyToClassrooms(Classroom::query())
            ->where('id', (int) $args['id'])
            ->first();

        if ($classroom === null) {
            throw ApiException::notFound();
        }

        if ($classroom->isClosed()) {
            throw ApiException::validation(
                'Bu kurs tamamlandy — täze okuwçy goşup bolmaýar.',
                'Курс завершён — добавить ученика нельзя.'
            );
        }

        $body = $this->body($request);

        $student = $this->students->create(
            $scope,
            $classroom,
            trim((string) ($body['login'] ?? '')),
            (string) ($body['password'] ?? ''),
            trim((string) ($body['full_name'] ?? ''))
        );

        return $this->json($response, [
            'student' => [
                'id'        => (int) $student->id,
                'login'     => $student->login,
                'full_name' => $student->full_name,
            ],
        ], 201);
    }

    /**
     * FR-9.3: an admin sees progress across the whole centre. No points
     * (FR-13.7) — the health numbers are attempt counts and the average
     * section percentage.
     */
    public function progress(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $scope = $this->scope($request);
        $scope->requireRole(User::ROLE_SUPERADMIN, User::ROLE_ADMIN);

        $classrooms = $scope->applyToClassrooms(Classroom::query())
            ->with(['teacher', 'level', 'center'])
            ->get();

        // Per-classroom counts (active students, attempts). The AVERAGE
        // is NOT taken here: AVG(percent_sum/attempts) counts only the
        // sections a student opened, so a class that did 1 of 10 sections
        // at 90% would read 90% — while the teacher register, leaderboard
        // and CSV all use the FR-13.8 equal split where unopened sections
        // weigh zero. The average is rebuilt below with the same formula.
        $totals = Capsule::table('student_section_stats')
            ->whereIn('classroom_id', $classrooms->pluck('id')->all())
            ->selectRaw('classroom_id,
                         COUNT(DISTINCT student_id) AS active,
                         COALESCE(SUM(attempts),0) AS attempts')
            ->groupBy('classroom_id')
            ->get()
            ->keyBy('classroom_id');

        // Every stats row, grouped by classroom then student, so each
        // student's equal-split correctness can be computed and averaged.
        $statsByClassroom = Capsule::table('student_section_stats')
            ->whereIn('classroom_id', $classrooms->pluck('id')->all())
            ->get()
            ->groupBy('classroom_id');

        // levelMap is per level, and several classrooms can share one —
        // cache so a centre of same-level classes maps once.
        $mapCache = [];
        $classroomAverage = function (Classroom $c) use ($statsByClassroom, &$mapCache): ?float {
            $rows = $statsByClassroom[$c->id] ?? collect();
            if ($rows->isEmpty()) {
                return null;
            }

            $levelId = (int) $c->level_id;
            $map = $mapCache[$levelId] ??= $this->stats->levelMap($levelId);

            $sum = 0.0;
            $students = $rows->groupBy('student_id');
            foreach ($students as $studentRows) {
                $avgBySection = [];
                foreach ($studentRows as $stat) {
                    $avgBySection[(int) $stat->section_id] =
                        (float) $stat->percent_sum / (int) $stat->attempts;
                }
                $sum += StatsService::correctness($map['by_child'], $avgBySection);
            }

            return round($sum / $students->count(), 1);
        };

        return $this->json($response, [
            'classrooms' => $classrooms->map(function (Classroom $c) use ($totals, $classroomAverage): array {
                $row = $totals[$c->id] ?? null;

                return [
                    'id'          => (int) $c->id,
                    'name'        => $c->name,
                    // The superadmin's view groups by centre, so every
                    // row carries the one it belongs to.
                    'center_id'   => (int) $c->center_id,
                    'center'      => $c->center?->name,
                    'level'       => $c->level?->name,
                    'teacher'     => $c->teacher?->full_name,
                    'closed'      => $c->isClosed(),
                    'students'    => $c->students()->where('is_active', 1)->count(),
                    // How many students have completed at least one
                    // section, how much they attempted, and how well.
                    'active'      => (int) ($row->active ?? 0),
                    'attempts'    => (int) ($row->attempts ?? 0),
                    'average'     => $classroomAverage($c),
                ];
            })->all(),
        ]);
    }

    // ------------------------------------------------- student credentials

    /**
     * FR-13.17: the reveal path moved here from the teacher routes and
     * is admin-only now. Still audited on every call (FR-1.12) and
     * rate-limited in StudentAccountService — the move changed who may
     * ask, not how carefully the answer is given.
     */
    public function revealStudentPassword(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $scope = $this->scope($request);
        $scope->requireRole(User::ROLE_ADMIN);

        $student = $this->studentById((int) $args['id']);

        $password = $this->students->reveal(
            $scope,
            $student,
            $request->getServerParams()['REMOTE_ADDR'] ?? null
        );

        return $this->json($response, [
            'student_id' => (int) $student->id,
            'login'      => $student->login,
            'password'   => $password,
        ]);
    }

    /** FR-13.11: the centre admin sets every student password. */
    public function resetStudentPassword(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $scope = $this->scope($request);
        $scope->requireRole(User::ROLE_ADMIN);

        $this->students->resetPassword(
            $scope,
            $this->studentById((int) $args['id']),
            (string) ($this->body($request)['password'] ?? '')
        );

        return $this->json($response, ['ok' => true]);
    }

    /**
     * The class register for the admin's credentials UI (FR-13.11):
     * who is enrolled, and whether their password can be shown. The
     * classroom lookup is scoped, so an admin reads their centre only.
     */
    public function classroomStudents(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $scope = $this->scope($request);
        $scope->requireRole(User::ROLE_ADMIN, User::ROLE_SUPERADMIN);

        $classroom = $scope->applyToClassrooms(Classroom::query())
            ->where('id', (int) $args['id'])
            ->first();

        if ($classroom === null) {
            throw ApiException::notFound();
        }

        return $this->json($response, [
            'classroom' => ['id' => (int) $classroom->id, 'name' => $classroom->name],
            'students'  => $classroom->students()->orderBy('full_name')->get()
                ->map(fn (User $s): array => [
                    'id'         => (int) $s->id,
                    'full_name'  => $s->full_name,
                    'login'      => $s->login,
                    'is_active'  => (bool) $s->is_active,
                    // Lets the panel grey the reveal button out instead
                    // of surfacing a 422 after the click.
                    'can_reveal' => $s->password_ct !== null,
                ])->all(),
        ]);
    }

    /**
     * FR-13.18: the centre admin views a TEACHER's current password.
     * Permission, throttle and audit all live in StaffAccountService —
     * admins stay reset-only there, so this route cannot leak one.
     */
    public function revealStaffPassword(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $scope = $this->scope($request);
        $scope->requireRole(User::ROLE_ADMIN);

        $teacher = User::query()->find((int) $args['id']);

        if ($teacher === null) {
            throw ApiException::notFound();
        }

        $password = $this->staff->reveal(
            $scope,
            $teacher,
            $request->getServerParams()['REMOTE_ADDR'] ?? null
        );

        return $this->json($response, [
            'staff_id' => (int) $teacher->id,
            'login'    => $teacher->login,
            'password' => $password,
        ]);
    }

    private function studentById(int $id): User
    {
        $student = User::query()->with('classroom')->find($id);

        if ($student === null) {
            throw ApiException::notFound();
        }

        return $student;
    }

    // ---------------------------------------------------------- content

    /**
     * The curriculum as the superadmin sees it — child units with their
     * TYPED sections (§13), drafts included, which students never
     * receive (FR-13.20).
     */
    public function content(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->scope($request)->requireRole(User::ROLE_SUPERADMIN);

        $childUnits = Capsule::table('unit_sections as cu')
            ->join('units as u', 'u.id', '=', 'cu.unit_id')
            ->join('levels as l', 'l.id', '=', 'u.level_id')
            ->orderBy('l.sort_order')
            ->orderBy('cu.level_position')
            ->select([
                'cu.id', 'cu.code', 'cu.label as cu_label', 'cu.title',
                'u.id as unit_id', 'u.number as unit_number', 'u.name as unit_name',
                'l.name as level_name', 'l.id as level_id',
            ])
            ->get();

        $childIds = $childUnits->pluck('id')->all();

        $sections = Capsule::table('sections')
            ->whereIn('unit_section_id', $childIds)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->groupBy('unit_section_id');

        $sectionIds = $sections->flatten()->pluck('id')->all();

        $vocab = Capsule::table('vocabulary_items')
            ->whereIn('section_id', $sectionIds)
            ->selectRaw('section_id, COUNT(*) AS n')
            ->groupBy('section_id')->pluck('n', 'section_id');

        $grammar = Capsule::table('grammar_explanations')
            ->whereIn('section_id', $sectionIds)
            ->pluck('id', 'section_id');

        // One aggregate per section: how many sets, how many live
        // questions, and how many of those feed the Exam Quiz (FR-13.4)
        // — the quiz row's preview count derives from the siblings'.
        $sets = Capsule::table('exercise_sets as es')
            ->leftJoin('questions as q', function ($join): void {
                $join->on('q.exercise_set_id', '=', 'es.id')->where('q.is_active', '=', 1);
            })
            ->whereIn('es.section_id', $sectionIds)
            ->groupBy('es.section_id')
            ->selectRaw('es.section_id,
                         COUNT(DISTINCT es.id) AS sets,
                         COUNT(q.id) AS questions,
                         COALESCE(SUM(q.quiz_eligible), 0) AS eligible')
            ->get()
            ->keyBy('section_id');

        $attempts = Capsule::table('section_attempts')
            ->whereIn('section_id', $sectionIds)
            ->selectRaw('section_id, COUNT(*) AS n')
            ->groupBy('section_id')
            ->pluck('n', 'section_id');

        // FR-14.5: books are out of the product — content arrives only via
        // manual authoring or xlsx, so there is no page transcription to
        // wait on. The dormant book_pages / section_sources tables are no
        // longer queried; `pages_ready` stays in the shape as a constant 0
        // so the panel contract does not shift.

        return $this->json($response, [
            'child_units' => $childUnits->map(fn ($cu): array => [
                'id'          => (int) $cu->id,
                'level'       => $cu->level_name,
                'unit_id'     => (int) $cu->unit_id,
                'unit_number' => (int) $cu->unit_number,
                // Manual naming (2026-08-20): explicit unit name / child
                // label win verbatim; legacy rows keep composing.
                'unit_name'   => $cu->unit_name,
                'label'       => $cu->cu_label ?? ($cu->unit_number . $cu->code),
                'title'       => $cu->title,
                'pages_ready' => 0,
                'sections'    => collect($sections[$cu->id] ?? [])->map(fn ($s): array => [
                    'id'         => (int) $s->id,
                    'type'       => $s->type,
                    'status'     => $s->status,
                    'title_tk'   => $s->title_tk,
                    'title_ru'   => $s->title_ru,
                    'vocabulary' => (int) ($vocab[$s->id] ?? 0),
                    'grammar'    => isset($grammar[$s->id]),
                    'sets'       => (int) ($sets[$s->id]->sets ?? 0),
                    'questions'  => (int) ($sets[$s->id]->questions ?? 0),
                    'eligible'   => (int) ($sets[$s->id]->eligible ?? 0),
                    'attempts'   => (int) ($attempts[$s->id] ?? 0),
                ])->values()->all(),
            ])->all(),
        ]);
    }

    /**
     * The review gate (FR-13.20). The SECTION is the visibility gate
     * students see; sets keep their own status as a second, inner gate.
     * Grammar no longer has a status of its own — it shows iff its
     * grammar section is published.
     */
    public function setContentStatus(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $scope = $this->scope($request);
        $scope->requireRole(User::ROLE_SUPERADMIN);

        $status = (string) ($this->body($request)['status'] ?? '');

        // The route pattern accepts any {kind}; only these two exist.
        if (!in_array($args['kind'], ['sections', 'sets'], true)) {
            throw ApiException::notFound();
        }

        $isSection = $args['kind'] === 'sections';
        $allowed = $isSection ? ['draft', 'published'] : ['draft', 'in_review', 'published'];

        if (!in_array($status, $allowed, true)) {
            throw ApiException::validation('Nädogry ýagdaý.', 'Недопустимый статус.');
        }

        $table = $isSection ? 'sections' : 'exercise_sets';
        $id = (int) $args['id'];

        $updated = Capsule::table($table)->where('id', $id)->update([
            'status'     => $status,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        if ($updated === 0) {
            throw ApiException::notFound();
        }

        Capsule::table('audit_log')->insert([
            'actor_id'    => $scope->userId,
            'action'      => 'content.status_changed',
            'entity_type' => $table,
            'entity_id'   => $id,
            'meta'        => json_encode(['status' => $status]),
            'created_at'  => date('Y-m-d H:i:s'),
        ]);

        return $this->json($response, ['id' => $id, 'status' => $status]);
    }

    public function exportClassroom(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->scope($request)->requireRole(User::ROLE_SUPERADMIN, User::ROLE_ADMIN);

        $csv = $this->closures->exportCsv($this->scope($request), (int) $args['id']);
        $response->getBody()->write($csv);

        return $response
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="classroom-' . (int) $args['id'] . '.csv"');
    }

    /** FR-1.14 — irreversible. Requires the classroom name as confirmation. */
    public function closeClassroom(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $result = $this->closures->close(
            $this->scope($request),
            (int) $args['id'],
            (string) ($this->body($request)['confirm_name'] ?? '')
        );

        return $this->json($response, $result);
    }
}
