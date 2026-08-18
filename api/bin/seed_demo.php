<?php

declare(strict_types=1);

/**
 * Sets up a working centre, classroom, teacher and student, plus the
 * Unit 1 curriculum structure mapped to the real Student's Book pages.
 *
 * Structure comes from the book's own contents page, transcribed during
 * OCR — Unit 1 has sections A (pp. 6-7) and B (pp. 8-9). "Practical
 * English Episode 1" is deliberately not a section: it is speaking and
 * listening material, which is excluded from exercise generation
 * (FR-4.9).
 *
 *   php bin/seed_demo.php
 */

require __DIR__ . '/../vendor/autoload.php';

use Dana\Domain\Auth\CredentialService;
use Dana\Domain\Models\Book;
use Dana\Domain\Models\BookSet;
use Dana\Domain\Models\Level;
use Dana\Domain\Models\User;
use Dana\Database\Bootstrap;
use Dana\Support\Config;
use Illuminate\Database\Capsule\Manager as Capsule;

$config = Config::load(dirname(__DIR__));
Bootstrap::boot($config);

$credentials = new CredentialService($config->require('APP_CRED_KEY'));
$now = date('Y-m-d H:i:s');

$level = Level::query()->where('slug', 'beginner')->firstOrFail();

// Derive the set from the book that was actually ingested. Picking the
// first set for the level would silently choose an empty one if any
// stray set exists.
$studentsBook = Book::query()
    ->where('level_id', $level->id)
    ->where('kind', Book::KIND_STUDENTS_BOOK)
    ->where('text_status', 'ready')
    ->orderByDesc('id')
    ->firstOrFail();

$bookSet = BookSet::query()->findOrFail($studentsBook->book_set_id);

// Remove any book set with no books and no classrooms pointing at it.
Capsule::table('book_sets')
    ->whereNotIn('id', fn ($q) => $q->select('book_set_id')->from('books'))
    ->whereNotIn('id', fn ($q) => $q->select('book_set_id')->from('classrooms'))
    ->delete();

$superadmin = User::query()->where('role', 'superadmin')->firstOrFail();

// ---- centre, teacher, classroom, student ----------------------------

$centerId = Capsule::table('centers')->where('name', 'Dana Ashgabat')->value('id')
    ?? Capsule::table('centers')->insertGetId([
        'name' => 'Dana Ashgabat', 'city' => 'Ashgabat', 'is_active' => 1,
        'created_at' => $now, 'updated_at' => $now,
    ]);

// Idempotent: re-running always restores the documented demo password,
// so a row left behind by an earlier test cannot leave the account
// unusable with a placeholder hash.
$teacher = User::query()->where('login', '+99312000001')->first() ?? new User();
$teacher->role = User::ROLE_TEACHER;
$teacher->center_id = $centerId;
$teacher->classroom_id = null;
$teacher->login = '+99312000001';
$teacher->password_hash = $credentials->hash('teacher');
$teacher->full_name = 'Aygul Nuryyewa';
$teacher->interface_lang = 'tk';
$teacher->is_active = true;
$teacher->created_by = $superadmin->id;
$teacher->created_at ??= $now;
$teacher->updated_at = $now;
$teacher->save();

$classroomId = Capsule::table('classrooms')
    ->where('teacher_id', $teacher->id)->where('name', 'Beginner Morning')->value('id')
    ?? Capsule::table('classrooms')->insertGetId([
        'center_id' => $centerId, 'teacher_id' => $teacher->id, 'level_id' => $level->id,
        'book_set_id' => $bookSet->id, 'name' => 'Beginner Morning', 'started_on' => date('Y-m-d'),
        'is_active' => 1, 'created_by' => $teacher->id, 'created_at' => $now, 'updated_at' => $now,
    ]);

$student = User::query()->where('login', '+99365123456')->first() ?? new User();
$student->role = User::ROLE_STUDENT;
$student->center_id = $centerId;
$student->classroom_id = $classroomId;
$student->login = '+99365123456';
$student->password_hash = $credentials->hash('student');
$student->full_name = 'Merdan Erkinow';
$student->interface_lang = 'tk';
$student->is_active = true;
$student->created_by = $teacher->id;
$student->password_set_at = $now;
$student->created_at ??= $now;
$student->updated_at = $now;
$student->save();

// FR-1.10: the reveal copy is bound to the user id, so it is written
// after the row exists and re-written whenever the password changes.
$encrypted = $credentials->encryptFor((int) $student->id, 'student');
Capsule::table('users')->where('id', $student->id)->update([
    'password_ct'  => $encrypted['ct'],
    'password_iv'  => $encrypted['iv'],
    'password_tag' => $encrypted['tag'],
]);

// ---- Unit 1, from the book's contents page --------------------------

$unitId = Capsule::table('units')->where('level_id', $level->id)->where('number', 1)->value('id')
    ?? Capsule::table('units')->insertGetId([
        'level_id' => $level->id, 'number' => 1, 'title' => null, 'sort_order' => 1,
    ]);

$sections = [
    ['code' => 'A', 'title' => 'A cappuccino, please', 'from' => 6, 'to' => 7, 'position' => 1],
    ['code' => 'B', 'title' => 'World music',          'from' => 8, 'to' => 9, 'position' => 2],
];

foreach ($sections as $index => $spec) {
    $sectionId = Capsule::table('unit_sections')
        ->where('unit_id', $unitId)->where('code', $spec['code'])->value('id')
        ?? Capsule::table('unit_sections')->insertGetId([
            'unit_id'        => $unitId,
            'code'           => $spec['code'],
            'title'          => $spec['title'],
            'sort_order'     => $index + 1,
            'level_position' => $spec['position'],
        ]);

    // FR-4.15: page ranges confirmed by a human before generation. Here
    // they come from the book's own contents page, verified by OCR.
    $exists = Capsule::table('section_sources')
        ->where('unit_section_id', $sectionId)
        ->where('book_id', $studentsBook->id)
        ->exists();

    if (!$exists) {
        Capsule::table('section_sources')->insert([
            'unit_section_id' => $sectionId,
            'book_id'         => $studentsBook->id,
            'page_from'       => $spec['from'],
            'page_to'         => $spec['to'],
            'confirmed_by'    => $superadmin->id,
            'confirmed_at'    => $now,
        ]);
    }

    // FR-7.4: the teacher has started both sections.
    $unlocked = Capsule::table('section_unlocks')
        ->where('classroom_id', $classroomId)->where('unit_section_id', $sectionId)->exists();

    if (!$unlocked) {
        Capsule::table('section_unlocks')->insert([
            'classroom_id'    => $classroomId,
            'unit_section_id' => $sectionId,
            'unlocked_by'     => $teacher->id,
            'unlocked_at'     => $now,
        ]);
    }

    echo "Section 1{$spec['code']} '{$spec['title']}' -> SB pp.{$spec['from']}-{$spec['to']} (id {$sectionId})\n";
}

echo "\nDemo accounts\n";
echo "  superadmin  +99363538839 / azim\n";
echo "  teacher     +99312000001 / teacher\n";
echo "  student     +99365123456 / student\n";
echo "\nClassroom {$classroomId} 'Beginner Morning' — both sections unlocked.\n";
