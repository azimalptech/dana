<?php

declare(strict_types=1);

/**
 * The §13 attempt engine, end to end.
 *
 * Covers the redesign's core promises: a submission produces a
 * percentage (FR-13.7), repeat attempts average (FR-13.6), the Exam
 * Quiz composes exactly the eligible questions of its published
 * siblings in source order (FR-13.4), check() stores nothing and an
 * incomplete answer set writes nothing (FR-13.5), and fill_letter_space
 * grades case-insensitively (FR-13.21).
 *
 *   C:/xampp/php/php.exe tests/attempts_test.php
 *
 * Creates its own centre, level, classroom and student with test-only
 * identifiers, and removes exactly those rows afterwards. It never
 * touches seeded or real data, so it is safe to run against a working
 * database.
 */

require __DIR__ . '/../vendor/autoload.php';

use Dana\Database\Bootstrap;
use Dana\Domain\Content\ContentRepository;
use Dana\Domain\Models\ExerciseSet;
use Dana\Domain\Models\User;
use Dana\Domain\Progress\Grader;
use Dana\Domain\Progress\SectionAttemptService;
use Dana\Domain\Progress\StatsService;
use Dana\Http\ApiException;
use Dana\Support\Config;
use Illuminate\Database\Capsule\Manager as Capsule;

$config = Config::load(dirname(__DIR__));
Bootstrap::boot($config);

const TEST_TAG = '__attempts_test__';

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? '  PASS  ' : '  FAIL  ') . $label . ($detail !== '' ? "  ({$detail})" : '') . PHP_EOL;
}

$ids = [];

/** Removes only what this test created, innermost first. */
function cleanup(array $ids): void
{
    if ($ids === []) {
        return;
    }

    $attemptIds = Capsule::table('section_attempts')
        ->where('student_id', $ids['student'] ?? 0)->pluck('id')->all();

    if ($attemptIds !== []) {
        Capsule::table('attempt_answers')->whereIn('attempt_id', $attemptIds)->delete();
    }

    Capsule::table('section_attempts')->where('student_id', $ids['student'] ?? 0)->delete();
    Capsule::table('student_section_stats')->where('student_id', $ids['student'] ?? 0)->delete();
    Capsule::table('student_activity_days')->where('student_id', $ids['student'] ?? 0)->delete();
    // sections cascade into exercise_sets, which cascade into questions.
    Capsule::table('sections')->where('unit_section_id', $ids['childUnit'] ?? 0)->delete();
    Capsule::table('unit_sections')->where('id', $ids['childUnit'] ?? 0)->delete();
    Capsule::table('units')->where('id', $ids['unit'] ?? 0)->delete();
    Capsule::table('users')->whereIn('id', [$ids['student'] ?? 0])->delete();
    Capsule::table('classrooms')->where('id', $ids['classroom'] ?? 0)->delete();
    Capsule::table('users')->whereIn('id', [$ids['teacher'] ?? 0])->delete();
    Capsule::table('book_sets')->where('id', $ids['bookSet'] ?? 0)->delete();
    Capsule::table('levels')->where('id', $ids['level'] ?? 0)->delete();
    Capsule::table('centers')->where('id', $ids['center'] ?? 0)->delete();
}

/** @return int the new exercise set's id */
function makeSet(int $sectionId, string $type, int $sort, string $now): int
{
    return (int) Capsule::table('exercise_sets')->insertGetId([
        'section_id' => $sectionId, 'type' => $type,
        'title_tk' => TEST_TAG, 'title_ru' => TEST_TAG, 'status' => 'published',
        'sort_order' => $sort, 'created_at' => $now, 'updated_at' => $now,
    ]);
}

/** @return int the new question's id */
function makeQuestion(int $setId, int $sort, array $payload, bool $eligible, string $now): int
{
    return (int) Capsule::table('questions')->insertGetId([
        'exercise_set_id' => $setId,
        'question_type'   => 'text',
        'quiz_eligible'   => $eligible ? 1 : 0,
        'sort_order'      => $sort,
        'payload'         => json_encode($payload),
        'is_active'       => 1,
        'created_at'      => $now,
        'updated_at'      => $now,
    ]);
}

try {
    $now = date('Y-m-d H:i:s');

    $ids['center'] = Capsule::table('centers')->insertGetId(
        ['name' => TEST_TAG, 'is_active' => 0, 'created_at' => $now, 'updated_at' => $now]
    );
    $ids['level'] = Capsule::table('levels')->insertGetId(
        ['name' => TEST_TAG, 'slug' => 'attempts-test-level', 'sort_order' => 999,
         'is_active' => 0, 'created_at' => $now, 'updated_at' => $now]
    );
    $ids['teacher'] = Capsule::table('users')->insertGetId(
        ['role' => 'teacher', 'center_id' => $ids['center'], 'login' => '+99399000001',
         'password_hash' => 'x', 'full_name' => TEST_TAG, 'is_active' => 0,
         'created_at' => $now, 'updated_at' => $now]
    );
    $ids['bookSet'] = Capsule::table('book_sets')->insertGetId(
        ['level_id' => $ids['level'], 'name' => TEST_TAG, 'is_active' => 0,
         'created_at' => $now, 'updated_at' => $now]
    );
    $ids['classroom'] = Capsule::table('classrooms')->insertGetId(
        ['center_id' => $ids['center'], 'teacher_id' => $ids['teacher'], 'level_id' => $ids['level'],
         'book_set_id' => $ids['bookSet'], 'name' => TEST_TAG, 'is_active' => 0,
         'created_by' => $ids['teacher'], 'created_at' => $now, 'updated_at' => $now]
    );
    $ids['student'] = Capsule::table('users')->insertGetId(
        ['role' => 'student', 'center_id' => $ids['center'], 'classroom_id' => $ids['classroom'],
         'login' => '+99399000002', 'password_hash' => 'x', 'full_name' => TEST_TAG,
         'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]
    );
    $ids['unit'] = Capsule::table('units')->insertGetId(
        ['level_id' => $ids['level'], 'number' => 1, 'sort_order' => 1]
    );
    $ids['childUnit'] = Capsule::table('unit_sections')->insertGetId(
        ['unit_id' => $ids['unit'], 'code' => 'A', 'sort_order' => 1, 'level_position' => 1]
    );

    // Typed sections (§13): grammar + listening published, a quiz, and a
    // DRAFT sibling whose eligible question must never surface anywhere.
    $section = static fn (string $type, string $status, int $sort): int =>
        (int) Capsule::table('sections')->insertGetId([
            'unit_section_id' => $ids['childUnit'], 'type' => $type,
            'title_tk' => TEST_TAG, 'title_ru' => TEST_TAG,
            'status' => $status, 'sort_order' => $sort,
            'created_at' => $now, 'updated_at' => $now,
        ]);

    $grammarSection = $section('grammar', 'published', 0);
    $letterSection = $section('listening', 'published', 1);
    $quizSection = $section('quiz', 'published', 2);
    $draftSection = $section('grammar', 'draft', 3);

    // 10 multiple-choice questions; q2 and q5 are quiz-eligible.
    $mcSet = makeSet($grammarSection, 'multiple_choice', 0, $now);
    $mcIds = [];

    for ($i = 1; $i <= 10; $i++) {
        $mcIds[$i] = makeQuestion($mcSet, $i, [
            'stem'    => "Question {$i} ___ ?",
            'options' => ['right', 'wrong1', 'wrong2', 'wrong3'],
            'answer'  => 0,
        ], in_array($i, [2, 5], true), $now);
    }

    // Two fill_letter_space questions; the second is quiz-eligible.
    $flsSet = makeSet($letterSection, 'fill_letter_space', 0, $now);
    $fls1 = makeQuestion($flsSet, 1, ['text' => 'I just {want} to go Hawaii.', 'reveal' => 2], false, $now);
    $fls2 = makeQuestion($flsSet, 2, ['text' => 'She {is} a {teacher}.', 'reveal' => 1], true, $now);

    // The draft sibling's eligible question — the trap.
    $draftSet = makeSet($draftSection, 'multiple_choice', 0, $now);
    $draftQ = makeQuestion($draftSet, 1, [
        'stem' => 'Draft ___ ?', 'options' => ['a', 'b', 'c', 'd'], 'answer' => 0,
    ], true, $now);

    $student = User::query()->with('classroom')->find($ids['student']);
    $stats = new StatsService();
    $service = new SectionAttemptService(new Grader(), new ContentRepository($stats));

    echo "=== GET /sections/{id}: the question set ===\n";

    $set = $service->questionSet($student, $grammarSection);
    check('grammar section serves its 10 questions', count($set['questions']) === 10);
    check('question_count matches', $set['section']['question_count'] === 10);
    check(
        'answer key is NOT sent to the client',
        !array_key_exists('answer', $set['questions'][0]['payload'])
    );

    $fls = $service->questionSet($student, $letterSection);
    $parts = $fls['questions'][0]['payload']['parts'];
    check(
        'fill_letter_space payload is a parts array',
        $parts === [
            ['t' => 'I just '],
            ['blank' => ['given' => 'wa', 'count' => 2]],
            ['t' => ' to go Hawaii.'],
        ],
        json_encode($parts)
    );

    echo "\n=== check() grades but stores nothing (FR-13.5) ===\n";

    check('correct answer accepted', $service->check($student, $grammarSection, $mcIds[1], 'right') === true);
    check('wrong answer rejected', $service->check($student, $grammarSection, $mcIds[1], 'wrong2') === false);

    try {
        $service->check($student, $grammarSection, $draftQ, 'a');
        check('a foreign question id is refused', false);
    } catch (ApiException $e) {
        check('a foreign question id is refused', $e->errorCode === 'not_found');
    }

    check(
        'no attempt rows written by check()',
        Capsule::table('section_attempts')->where('student_id', $ids['student'])->count() === 0
        && Capsule::table('student_section_stats')->where('student_id', $ids['student'])->count() === 0
    );

    echo "\n=== submit computes the percentage (FR-13.7) ===\n";

    $answers = [];
    foreach ($mcIds as $i => $qid) {
        // 8 right, 2 wrong — the §8-era worked example, now worth 80%.
        $answers[] = ['question_id' => $qid, 'answer' => $i <= 8 ? 'right' : 'wrong1'];
    }

    $result = $service->submit($student, $grammarSection, $answers);
    check('percent is 80', $result['percent'] === 80.0, "got {$result['percent']}");
    check('correct 8 of 10', $result['correct'] === 8 && $result['total'] === 10);
    check('attempt_no is 1', $result['attempt_no'] === 1);
    check('average equals the single attempt', $result['average'] === 80.0);
    check(
        'per-question answers recorded',
        Capsule::table('attempt_answers')
            ->join('section_attempts', 'section_attempts.id', '=', 'attempt_answers.attempt_id')
            ->where('section_attempts.student_id', $ids['student'])
            ->count() === 10
    );

    echo "\n=== repeat attempts average (FR-13.6) ===\n";

    $allRight = array_map(
        static fn (int $qid): array => ['question_id' => $qid, 'answer' => 'right'],
        array_values($mcIds)
    );

    $result = $service->submit($student, $grammarSection, $allRight);
    check('second attempt_no is 2', $result['attempt_no'] === 2);
    check('second percent is 100', $result['percent'] === 100.0);
    check('average over two attempts is 90', $result['average'] === 90.0, "got {$result['average']}");
    check('history holds both attempts, newest first', count($result['history']) === 2
        && $result['history'][0]['attempt_no'] === 2
        && $result['history'][1]['percent'] === 80.0);

    $history = $service->history($student, $grammarSection);
    check('GET history agrees', $history['average'] === 90.0 && count($history['attempts']) === 2);

    echo "\n=== incomplete answer set is rejected (FR-13.5) ===\n";

    $before = Capsule::table('section_attempts')->where('student_id', $ids['student'])->count();

    try {
        $service->submit($student, $grammarSection, array_slice($allRight, 0, 9));
        check('nine answers out of ten refused', false);
    } catch (ApiException $e) {
        check('nine answers out of ten refused', $e->errorCode === 'validation_failed');
    }

    try {
        $tampered = array_slice($allRight, 0, 9);
        $tampered[] = ['question_id' => $draftQ, 'answer' => 'a'];
        $service->submit($student, $grammarSection, $tampered);
        check('a foreign question id in the set is refused', false);
    } catch (ApiException) {
        check('a foreign question id in the set is refused', true);
    }

    check(
        'nothing was written by the refused submissions',
        Capsule::table('section_attempts')->where('student_id', $ids['student'])->count() === $before
    );

    echo "\n=== fill_letter_space grading (FR-13.21) ===\n";

    check('missing letters, exact', $service->check($student, $letterSection, $fls1, ['nt']) === true);
    check('case-insensitive', $service->check($student, $letterSection, $fls1, ['NT']) === true);
    check('trimmed', $service->check($student, $letterSection, $fls1, [' nt ']) === true);
    check('wrong letters rejected', $service->check($student, $letterSection, $fls1, ['xx']) === false);
    check('two blanks in authored order', $service->check($student, $letterSection, $fls2, ['S', 'EACHER']) === true);
    check('a missing blank fails', $service->check($student, $letterSection, $fls2, ['s']) === false);

    $result = $service->submit($student, $letterSection, [
        ['question_id' => $fls1, 'answer' => ['Nt']],
        ['question_id' => $fls2, 'answer' => ['s', 'eacher']],
    ]);
    check('letter section submits at 100', $result['percent'] === 100.0);

    echo "\n=== quiz composition (FR-13.4) ===\n";

    $quiz = $service->questionSet($student, $quizSection);
    $quizIds = array_column($quiz['questions'], 'id');

    check(
        'quiz = eligible questions of published siblings, source order',
        $quizIds === [$mcIds[2], $mcIds[5], $fls2],
        json_encode($quizIds)
    );
    check('draft sibling\'s eligible question is excluded', !in_array($draftQ, $quizIds, true));
    check('quiz question_count is the eligible count', $quiz['section']['question_count'] === 3);

    $result = $service->submit($student, $quizSection, [
        ['question_id' => $mcIds[2], 'answer' => 'right'],
        ['question_id' => $mcIds[5], 'answer' => 'wrong3'],
        ['question_id' => $fls2, 'answer' => ['s', 'eacher']],
    ]);
    check('quiz percent is 66.67', abs($result['percent'] - 66.67) < 0.001, "got {$result['percent']}");

    echo "\n=== the rollups agree (FR-13.8 / FR-13.9) ===\n";

    // Section averages now: grammar 90, letter 100, quiz 66.67. One
    // child unit, three attemptable sections -> correctness 85.56,
    // leaderboard score 8556.
    $board = $stats->leaderboard($student);
    check('leaderboard score is 8556', $board['me']['score'] === 8556, "got {$board['me']['score']}");
    check('me is ranked first', $board['me']['rank'] === 1 && $board['me']['is_me'] === true);

    $outline = (new ContentRepository($stats))->outlineFor($student);
    check('outline leaderboard_score agrees', $outline['leaderboard_score'] === 8556);
    check('level_progress is 100 (all sections attempted)', $outline['level_progress'] === 100);

    $child = $outline['parent_units'][0]['child_units'][0];
    check('child unit completed', $child['state'] === 'completed');
    check('child success_rate is 85.6', abs($child['success_rate'] - 85.6) < 0.05, "got {$child['success_rate']}");
    check(
        'draft section is not in the outline',
        !in_array($draftSection, array_column($child['sections'], 'id'), true)
    );

    check(
        'activity day recorded for the streak (FR-8.10)',
        (int) Capsule::table('student_activity_days')
            ->where('student_id', $ids['student'])
            ->sum('exercises_completed') === 4
    );
} finally {
    cleanup($ids);
    echo "\nTest data removed.\n";
}

echo "{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
