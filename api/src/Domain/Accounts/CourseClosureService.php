<?php

declare(strict_types=1);

namespace Dana\Domain\Accounts;

use Dana\Domain\Models\Classroom;
use Dana\Domain\Models\User;
use Dana\Domain\Progress\StatsService;
use Dana\Domain\Scope;
use Dana\Http\ApiException;
use Illuminate\Database\Capsule\Manager as Capsule;
use Psr\Log\LoggerInterface;

/**
 * FR-1.14: when a course finishes, its student accounts are disabled and
 * their progress is not retained.
 *
 * This deletes data permanently and cannot be undone, so:
 *   - the caller must pass the classroom name back as confirmation,
 *   - a CSV of the final results is returned in the same response,
 *   - the whole thing runs in one transaction,
 *   - the audit row records who did it and how many accounts it touched.
 *
 * Once this has run, nobody — admin or superadmin — can see that
 * course's progress again. That is the requirement, but it means the
 * export is the only surviving record, so it is produced before anything
 * is removed rather than after.
 */
final class CourseClosureService
{
    public function __construct(
        private readonly StatsService $stats,
        private readonly LoggerInterface $log,
    ) {
    }

    /**
     * The final report — percentages only, no points (FR-13.7). The
     * average is the FR-13.8 level correctness, the same figure the
     * leaderboard showed, so the exported record matches what everyone
     * saw all course long.
     */
    public function exportCsv(Scope $scope, int $classroomId): string
    {
        $classroom = $this->classroom($scope, $classroomId);

        $students = Capsule::table('users')
            ->where('classroom_id', $classroom->id)
            ->where('role', User::ROLE_STUDENT)
            ->orderBy('full_name')
            ->select(['id', 'full_name', 'login'])
            ->get();

        $map = $this->stats->levelMap((int) $classroom->level_id);

        $stats = Capsule::table('student_section_stats')
            ->where('classroom_id', $classroom->id)
            ->get()
            ->groupBy('student_id');

        $rows = $students->map(function ($student) use ($map, $stats): array {
            $avgBySection = [];
            $attempts = 0;

            foreach ($stats[$student->id] ?? [] as $stat) {
                $avgBySection[(int) $stat->section_id] =
                    (float) $stat->percent_sum / (int) $stat->attempts;
                $attempts += (int) $stat->attempts;
            }

            return [
                'name'     => (string) $student->full_name,
                'login'    => (string) $student->login,
                'sections' => count($avgBySection),
                'attempts' => $attempts,
                'average'  => $avgBySection === []
                    ? null
                    : round(StatsService::correctness($map['by_child'], $avgBySection), 1),
            ];
        })->sortByDesc(fn (array $row): float => $row['average'] ?? -1.0)->values();

        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, ['Student', 'Login', 'Sections attempted', 'Attempts', 'Average %']);

        foreach ($rows as $row) {
            fputcsv($handle, [
                self::csvSafe($row['name']),
                self::csvSafe($row['login']),
                $row['sections'],
                $row['attempts'],
                $row['average'] ?? '',
            ]);
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * @return array{closed: bool, students_disabled: int, csv: string}
     */
    public function close(Scope $scope, int $classroomId, string $confirmName): array
    {
        $scope->requireRole(User::ROLE_ADMIN, User::ROLE_SUPERADMIN);
        $classroom = $this->classroom($scope, $classroomId);

        if ($classroom->isClosed()) {
            throw ApiException::validation(
                'Bu okuw eýýäm tamamlandy.',
                'Этот курс уже завершён.'
            );
        }

        // Typing the name back is the guard against closing the wrong
        // classroom from a list — the data cannot be recovered afterwards.
        if (trim($confirmName) !== $classroom->name) {
            throw ApiException::validation(
                'Tassyklamak üçin synpyň adyny dogry ýazyň.',
                'Для подтверждения введите название класса точно.'
            );
        }

        // Produced first: after the purge there is nothing left to report.
        $csv = $this->exportCsv($scope, $classroomId);

        $studentIds = Capsule::table('users')
            ->where('classroom_id', $classroom->id)
            ->where('role', User::ROLE_STUDENT)
            ->pluck('id')
            ->all();

        $now = date('Y-m-d H:i:s');

        Capsule::connection()->transaction(function () use ($classroom, $studentIds, $scope, $now): void {
            if ($studentIds !== []) {
                // FR-1.14 purge, §13 tables. attempt_answers would
                // cascade with its attempts anyway; deleting it
                // explicitly first is cheap and keeps the purge readable
                // as a complete list of what is destroyed.
                Capsule::table('attempt_answers')
                    ->whereIn('attempt_id', fn ($q) => $q->select('id')
                        ->from('section_attempts')
                        ->whereIn('student_id', $studentIds))
                    ->delete();
                Capsule::table('section_attempts')->whereIn('student_id', $studentIds)->delete();
                Capsule::table('student_section_stats')->whereIn('student_id', $studentIds)->delete();
                Capsule::table('student_bookmarks')->whereIn('student_id', $studentIds)->delete();
                Capsule::table('student_activity_days')->whereIn('student_id', $studentIds)->delete();
                Capsule::table('refresh_tokens')->whereIn('user_id', $studentIds)->delete();

                // Accounts are disabled, not deleted — deleting them would
                // break the audit trail that records who created them.
                // Credentials are destroyed either way.
                Capsule::table('users')->whereIn('id', $studentIds)->update([
                    'is_active'    => 0,
                    'password_hash' => password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT),
                    'password_ct'  => null,
                    'password_iv'  => null,
                    'password_tag' => null,
                    'updated_at'   => $now,
                ]);
            }

            Capsule::table('classrooms')->where('id', $classroom->id)->update([
                'closed_at'  => $now,
                'is_active'  => 0,
                'updated_at' => $now,
            ]);

            Capsule::table('audit_log')->insert([
                'actor_id'    => $scope->userId,
                'action'      => 'classroom.closed',
                'entity_type' => 'classroom',
                'entity_id'   => $classroom->id,
                'meta'        => json_encode([
                    'students_disabled' => count($studentIds),
                    'name'              => $classroom->name,
                ]),
                'created_at'  => $now,
            ]);
        });

        $this->log->warning('course closed and student data purged', [
            'actor_id'     => $scope->userId,
            'classroom_id' => $classroom->id,
            'students'     => count($studentIds),
        ]);

        return [
            'closed'            => true,
            'students_disabled' => count($studentIds),
            'csv'               => $csv,
        ];
    }

    /**
     * Excel and LibreOffice execute cells that begin with = + - or @.
     * Names are staff-entered free text, so "=HYPERLINK(...)" typed as
     * a student name would otherwise run on the admin's machine the
     * moment they open the export.
     */
    private static function csvSafe(string $value): string
    {
        return preg_match('/^[=+\-@]/', $value) === 1 ? "'" . $value : $value;
    }

    private function classroom(Scope $scope, int $classroomId): Classroom
    {
        $classroom = $scope->applyToClassrooms(Classroom::query())->where('id', $classroomId)->first();

        if ($classroom === null) {
            throw ApiException::notFound();
        }

        return $classroom;
    }
}
