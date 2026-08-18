<?php

declare(strict_types=1);

/**
 * The v2 content ENGINE (docs/06-CONTENT-V2.md §3/§6, FR-14.3).
 *
 *   C:/xampp/php/php.exe tests/content_v2_engine_test.php
 *
 * Two halves, one rolled-back transaction:
 *
 *  A. The client's real fixtures, imported and published, then:
 *     - a question with a pending (un-uploaded) media part is auto-hidden
 *       from section play AND from the levelMap denominators (§3);
 *     - setting its media_path makes it servable again;
 *     - export -> import is a zero-change round-trip (§6).
 *
 *  B. A hand-built child unit with quiz targets, to pin the fixed draw
 *     (§3): sized min(target, servable pool) per skill, stable across two
 *     serves, identical for two students, self-healing when a drawn
 *     question stops being servable, and re-drawable by the superadmin —
 *     and the drawn questions still grade through the normal attempt flow.
 *
 *  Plus the media route: MediaStorage rejects traversal and serves a
 *  stored file; the controller streams, uploads and clears.
 *
 * Disk media is written to a throwaway temp dir; the database work is a
 * savepoint inside the outer transaction and vanishes on rollback.
 */

require __DIR__ . '/../vendor/autoload.php';

use Dana\Database\Bootstrap;
use Dana\Domain\Content\ContentRepository;
use Dana\Domain\Content\XlsxExportService;
use Dana\Domain\Content\XlsxImportService;
use Dana\Domain\Models\ExerciseSet;
use Dana\Domain\Models\Question;
use Dana\Domain\Models\Section;
use Dana\Domain\Models\User;
use Dana\Domain\Progress\Grader;
use Dana\Domain\Progress\QuizDrawService;
use Dana\Domain\Progress\SectionAttemptService;
use Dana\Domain\Progress\StatsService;
use Dana\Domain\Scope;
use Dana\Http\ApiException;
use Dana\Http\Controllers\MediaController;
use Dana\Support\Config;
use Dana\Support\Media\MediaStorage;
use Dana\Support\Xlsx\XlsxReader;
use Dana\Support\Xlsx\XlsxWriter;
use Illuminate\Database\Capsule\Manager as Capsule;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\UploadedFile;

$config = Config::load(dirname(__DIR__));
Bootstrap::boot($config);

const FIXTURES = __DIR__ . '/fixtures';

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? '  PASS  ' : '  FAIL  ') . $label . ($detail !== '' ? "  ({$detail})" : '') . PHP_EOL;
}

/**
 * The three genuine typos in the pristine fixtures, patched exactly as in
 * content_v2_import_test (no content invented). Returns files ready to
 * import.
 *
 * @return list<array{name: string, path: string}>
 */
function correctedFiles(string $tmpDir): array
{
    $patches = [
        'Listening' => [[26, 0, 'U1-L-026']],
        'Grammar'   => [
            [29,  9, '[audio: 0, image: 0, text: "It isn\'t a dictionary."]'],
            [29, 10, '[audio: 0, image: 0, text: "isn\'t"]'],
            [41,  9, '[audio: 0, image: 0, text: "am / is"]'],
        ],
    ];

    $files = [];

    foreach (['Listening', 'Grammar'] as $skill) {
        $sheets = XlsxReader::open(FIXTURES . '/' . $skill . '.xlsx')->sheets();
        $sheetName = (string) array_key_first($sheets);
        $rows = $sheets[$sheetName];

        foreach ($patches[$skill] as [$rowIdx, $colIdx, $value]) {
            while (count($rows[$rowIdx]) <= $colIdx) {
                $rows[$rowIdx][] = '';
            }
            $rows[$rowIdx][$colIdx] = $value;
        }

        $header = array_shift($rows);
        $writer = new XlsxWriter();
        $writer->addSheet($sheetName, $header, $rows);
        $path = $tmpDir . '/' . $skill . '.xlsx';
        $writer->saveTo($path);
        $files[] = ['name' => $skill . '.xlsx', 'path' => $path];
    }

    foreach (['Vocabulary', 'UnitQuiz', 'Wordlist'] as $name) {
        $files[] = ['name' => $name . '.xlsx', 'path' => FIXTURES . '/' . $name . '.xlsx'];
    }

    return $files;
}

/** All media parts of a payload get a (fake) media_path — makes it servable. */
function fillMedia(array $payload): array
{
    if (isset($payload['stem']) && is_array($payload['stem'])
        && (isset($payload['stem']['audio_note']) || isset($payload['stem']['image_note']))) {
        $payload['stem']['media_path'] = 'media/filled-stem';
    }

    foreach ($payload['options'] ?? [] as $i => $opt) {
        if (is_array($opt) && (isset($opt['audio_note']) || isset($opt['image_note']))) {
            $payload['options'][$i]['media_path'] = 'media/filled-opt' . $i;
        }
    }

    return $payload;
}

$tmpDir = sys_get_temp_dir() . '/dana_engine_' . getmypid();
$mediaTmp = sys_get_temp_dir() . '/dana_engine_media_' . getmypid();

foreach ([$tmpDir, $mediaTmp] as $dir) {
    if (!is_dir($dir) && !mkdir($dir) && !is_dir($dir)) {
        fwrite(STDERR, "Cannot create temp dir {$dir}\n");
        exit(1);
    }
}

$connection = Capsule::connection();
$connection->beginTransaction();

try {
    $now = date('Y-m-d H:i:s');

    // Shadow-rename anything the fixtures' "Beginner / A1" would capture,
    // and any pre-existing 1/A child unit the UnitQuiz sheet could collide
    // with. Both come back on rollback.
    Capsule::table('levels')
        ->whereRaw("LOWER(TRIM(name)) IN ('beginner / a1', 'beginner', 'a1')")
        ->update(['name' => Capsule::raw("CONCAT(name, ' engineshadow')")]);
    Capsule::table('unit_sections')
        ->whereIn('unit_id', Capsule::table('units')->where('number', 1)->pluck('id')->all())
        ->whereRaw("UPPER(TRIM(code)) = 'A'")
        ->update(['code' => Capsule::raw("CONCAT('z', id)")]);

    $fixtureLevelId = (int) Capsule::table('levels')->insertGetId([
        'name' => 'A1', 'slug' => 'cv2-engine-' . getmypid(), 'sort_order' => 999,
        'is_active' => 0, 'created_at' => $now, 'updated_at' => $now,
    ]);

    $stats = new StatsService();
    $draws = new QuizDrawService();
    $service = new SectionAttemptService(new Grader(), new ContentRepository($stats), $draws);

    // ===================================================================
    echo "=== A. fixtures: import, publish ===\n";

    $importFiles = correctedFiles($tmpDir);
    $result = (new XlsxImportService())->import($importFiles);
    check('fixtures import clean', $result['errors'] === [] && $result['created']['questions'] === 134,
        json_encode($result['errors'], JSON_UNESCAPED_UNICODE));

    $childId = (int) Capsule::table('unit_sections as cu')
        ->join('units as u', 'u.id', '=', 'cu.unit_id')
        ->where('u.level_id', $fixtureLevelId)->where('u.number', 1)->where('cu.code', 'A')
        ->value('cu.id');

    // Publish every section of the child unit so students can reach them.
    Capsule::table('sections')->where('unit_section_id', $childId)
        ->update(['status' => Section::STATUS_PUBLISHED, 'updated_at' => $now]);

    $fixSections = Capsule::table('sections')->where('unit_section_id', $childId)->get()->keyBy('type');
    $listeningId = (int) $fixSections['listening']->id;
    $quizFixId = (int) $fixSections['quiz']->id;

    // A student on the fixture level.
    $centerA = (int) Capsule::table('centers')->insertGetId(
        ['name' => '__engine_A__', 'is_active' => 0, 'created_at' => $now, 'updated_at' => $now]);
    $teacherA = (int) Capsule::table('users')->insertGetId([
        'role' => 'teacher', 'center_id' => $centerA, 'login' => '+99399900100',
        'password_hash' => 'x', 'full_name' => '__engine_A__', 'is_active' => 0,
        'created_at' => $now, 'updated_at' => $now,
    ]);
    $classA = (int) Capsule::table('classrooms')->insertGetId([
        'center_id' => $centerA, 'teacher_id' => $teacherA, 'level_id' => $fixtureLevelId,
        'book_set_id' => null, 'name' => '__engine_A__', 'is_active' => 0,
        'created_by' => $teacherA, 'created_at' => $now, 'updated_at' => $now,
    ]);
    $studentAId = (int) Capsule::table('users')->insertGetId([
        'role' => 'student', 'center_id' => $centerA, 'classroom_id' => $classA,
        'login' => '+99399900101', 'password_hash' => 'x', 'full_name' => '__engine_A__',
        'is_active' => 1, 'created_at' => $now, 'updated_at' => $now,
    ]);
    $studentA = User::query()->with('classroom')->find($studentAId);

    // ===================================================================
    echo "\n=== A. export -> import round-trips with zero changes (§6) ===\n";

    $roundTripDir = $tmpDir . '/rt';
    mkdir($roundTripDir);
    $exportPath = $roundTripDir . '/export.xlsx';
    (new XlsxExportService())->workbook(XlsxExportService::SCOPE_LEVEL, $fixtureLevelId)->saveTo($exportPath);

    $sheetNames = array_keys(XlsxReader::open($exportPath)->sheets());
    check('export has the five v2 sheets',
        $sheetNames === ['Listening', 'Grammar', 'Vocabulary', 'Wordlist', 'UnitQuiz'],
        json_encode($sheetNames));

    $reimport = (new XlsxImportService())->import([['name' => 'export.xlsx', 'path' => $exportPath]]);
    check('round-trip: zero errors', $reimport['errors'] === [], json_encode($reimport['errors'], JSON_UNESCAPED_UNICODE));
    check('round-trip: nothing created', array_sum($reimport['created']) === 0, json_encode($reimport['created']));
    check('round-trip: nothing updated', array_sum($reimport['updated']) === 0, json_encode($reimport['updated']));

    // ===================================================================
    echo "\n=== A. non-servable questions are hidden (§3) ===\n";

    $listeningRows = Capsule::table('questions as q')
        ->join('exercise_sets as es', 'es.id', '=', 'q.exercise_set_id')
        ->where('es.section_id', $listeningId)->where('q.is_active', 1)
        ->get(['q.id', 'q.payload']);

    $servableIds = [];
    $nonServableIds = [];
    foreach ($listeningRows as $row) {
        if (Question::payloadServable(json_decode((string) $row->payload, true))) {
            $servableIds[] = (int) $row->id;
        } else {
            $nonServableIds[] = (int) $row->id;
        }
    }

    // Every fixture listening question carries audio, so none is servable
    // until its file is uploaded — the strongest demonstration of §3
    // hiding: many active questions, none reaching the student.
    check('the listening section has active pending-media (hidden) questions',
        $nonServableIds !== [],
        count($servableIds) . ' servable / ' . count($nonServableIds) . ' hidden');

    $served = $service->questionSet($studentA, $listeningId);
    $servedIds = array_column($served['questions'], 'id');
    check('serve returns exactly the servable questions',
        count($servedIds) === count($servableIds)
        && array_diff($servedIds, $servableIds) === [],
        count($servedIds) . ' served vs ' . count($servableIds) . ' servable');
    check('a pending-media question is NOT served',
        !in_array($nonServableIds[0], $servedIds, true));

    $map = $stats->levelMap($fixtureLevelId);
    check('levelMap counts the listening section as servable-only',
        ($map['counts'][$listeningId] ?? -1) === count($servableIds),
        'levelMap=' . ($map['counts'][$listeningId] ?? -1) . ' servable=' . count($servableIds));

    echo "\n=== A. uploading media makes a question servable (§3) ===\n";

    $flipId = $nonServableIds[0];
    $flipPayload = fillMedia(json_decode((string) Capsule::table('questions')->where('id', $flipId)->value('payload'), true));
    Capsule::table('questions')->where('id', $flipId)
        ->update(['payload' => json_encode($flipPayload, JSON_UNESCAPED_UNICODE)]);

    check('the flipped question is now servable',
        Question::payloadServable($flipPayload));
    $served2 = array_column($service->questionSet($studentA, $listeningId)['questions'], 'id');
    check('it now appears in section play',
        in_array($flipId, $served2, true) && count($served2) === count($servableIds) + 1);
    $map2 = $stats->levelMap($fixtureLevelId);
    check('and in the levelMap denominator',
        ($map2['counts'][$listeningId] ?? -1) === count($servableIds) + 1);

    // ===================================================================
    echo "\n=== B. fixed quiz draw: build a controlled unit ===\n";

    $centerB = (int) Capsule::table('centers')->insertGetId(
        ['name' => '__engine_B__', 'is_active' => 0, 'created_at' => $now, 'updated_at' => $now]);
    $levelB = (int) Capsule::table('levels')->insertGetId([
        'name' => '__engine_B__', 'slug' => 'cv2-engine-b-' . getmypid(), 'sort_order' => 998,
        'is_active' => 0, 'created_at' => $now, 'updated_at' => $now,
    ]);
    $teacherB = (int) Capsule::table('users')->insertGetId([
        'role' => 'teacher', 'center_id' => $centerB, 'login' => '+99399900200',
        'password_hash' => 'x', 'full_name' => '__engine_B__', 'is_active' => 0,
        'created_at' => $now, 'updated_at' => $now,
    ]);
    $classB = (int) Capsule::table('classrooms')->insertGetId([
        'center_id' => $centerB, 'teacher_id' => $teacherB, 'level_id' => $levelB,
        'book_set_id' => null, 'name' => '__engine_B__', 'is_active' => 0,
        'created_by' => $teacherB, 'created_at' => $now, 'updated_at' => $now,
    ]);
    $mkStudent = static function (string $login) use ($centerB, $classB, $now): int {
        return (int) Capsule::table('users')->insertGetId([
            'role' => 'student', 'center_id' => $centerB, 'classroom_id' => $classB,
            'login' => $login, 'password_hash' => 'x', 'full_name' => '__engine_B__',
            'is_active' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
    };
    $student1 = User::query()->with('classroom')->find($mkStudent('+99399900201'));
    $student2 = User::query()->with('classroom')->find($mkStudent('+99399900202'));

    $unitB = (int) Capsule::table('units')->insertGetId(
        ['level_id' => $levelB, 'number' => 1, 'sort_order' => 1]);
    // Targets: grammar 3 (pool 6 -> draws 3), listening 5 (pool 4 -> draws
    // the whole 4), vocabulary NULL (contributes nothing though its pool
    // is non-empty).
    $childB = (int) Capsule::table('unit_sections')->insertGetId([
        'unit_id' => $unitB, 'code' => 'A', 'sort_order' => 1, 'level_position' => 1,
        'quiz_target_vocabulary' => null, 'quiz_target_grammar' => 3, 'quiz_target_listening' => 5,
    ]);

    $mkSection = static function (string $type) use ($childB, $now): int {
        return (int) Capsule::table('sections')->insertGetId([
            'unit_section_id' => $childB, 'type' => $type, 'title_tk' => '__engine_B__',
            'title_ru' => '__engine_B__', 'status' => 'published', 'sort_order' => 0,
            'created_at' => $now, 'updated_at' => $now,
        ]);
    };
    $mkSet = static function (int $sectionId) use ($now): int {
        return (int) Capsule::table('exercise_sets')->insertGetId([
            'section_id' => $sectionId, 'type' => 'multiple_choice', 'title_tk' => '__engine_B__',
            'title_ru' => '__engine_B__', 'status' => 'published', 'sort_order' => 0,
            'created_at' => $now, 'updated_at' => $now,
        ]);
    };
    $mkQ = static function (int $setId, int $sort, array $payload, bool $eligible) use ($now): int {
        return (int) Capsule::table('questions')->insertGetId([
            'exercise_set_id' => $setId, 'question_type' => 'text', 'quiz_eligible' => $eligible ? 1 : 0,
            'sort_order' => $sort, 'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'is_active' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
    };
    // A servable multiple_choice question whose correct text is "right".
    $textQ = static fn (): array => ['stem' => 'Pick', 'options' => [['text' => 'right'], ['text' => 'w1'], ['text' => 'w2']], 'answer' => 0];
    // A pending-media (non-servable) question — still eligible.
    $audioQ = static fn (): array => ['stem' => ['audio_note' => 'x', 'media_path' => null], 'options' => [['text' => 'right'], ['text' => 'w1']], 'answer' => 0];

    $grammarB = $mkSection('grammar');
    $listeningB = $mkSection('listening');
    $vocabB = $mkSection('vocabulary');
    $quizB = $mkSection('quiz');

    $grammarSet = $mkSet($grammarB);
    $grammarPool = [];
    for ($i = 1; $i <= 6; $i++) {
        $grammarPool[] = $mkQ($grammarSet, $i, $textQ(), true);
    }
    // One eligible-but-not-servable grammar question (must stay out of the pool).
    $grammarHidden = $mkQ($grammarSet, 7, $audioQ(), true);

    $listeningSet = $mkSet($listeningB);
    $listeningPool = [];
    for ($i = 1; $i <= 4; $i++) {
        $listeningPool[] = $mkQ($listeningSet, $i, $textQ(), true);
    }

    $vocabSet = $mkSet($vocabB);
    for ($i = 1; $i <= 2; $i++) {
        $mkQ($vocabSet, $i, $textQ(), true);
    }
    // A question with an audio stem for the upload-route test.
    $uploadQ = $mkQ($vocabSet, 3, $audioQ(), false);

    echo "\n=== B. the draw is sized min(target, pool) per skill (§3) ===\n";

    check('grammar pool excludes the pending-media question',
        $draws->pool($childB, 'grammar') === $grammarPool,
        json_encode($draws->pool($childB, 'grammar')));

    $planned = $draws->plannedCounts($childB);
    check('planned counts: grammar 3, listening 4, vocabulary 0, total 7',
        $planned['grammar'] === 3 && $planned['listening'] === 4
        && $planned['vocabulary'] === 0 && $planned['total'] === 7,
        json_encode($planned));

    $draw = $draws->ensure($childB);
    $grammarPart = array_values(array_intersect($draw, $grammarPool));
    $listeningPart = array_values(array_intersect($draw, $listeningPool));
    check('the draw is 3 grammar + 4 listening + 0 vocabulary = 7',
        count($draw) === 7 && count($grammarPart) === 3 && count($listeningPart) === 4);
    check('the pending-media grammar question is not drawn',
        !in_array($grammarHidden, $draw, true));
    check('draw order is grammar group then listening group',
        array_slice($draw, 0, 3) === $grammarPart && array_slice($draw, 3, 4) === $listeningPart,
        json_encode($draw));

    echo "\n=== B. the draw is fixed across serves and students (§3) ===\n";

    check('a second ensure() returns the identical set', $draws->ensure($childB) === $draw);

    $serve1 = array_column($service->questionSet($student1, $quizB)['questions'], 'id');
    $serve2 = array_column($service->questionSet($student2, $quizB)['questions'], 'id');
    check('two students receive the very same quiz', $serve1 === $serve2, json_encode([$serve1, $serve2]));
    check('served quiz equals the stored draw', $serve1 === $draw);
    check('the served quiz count is 7', count($serve1) === 7);

    $mapB = $stats->levelMap($levelB);
    check('levelMap sizes the quiz section at the draw size (7)',
        ($mapB['counts'][$quizB] ?? -1) === 7, 'got ' . ($mapB['counts'][$quizB] ?? -1));

    echo "\n=== B. the drawn questions grade through the attempt flow ===\n";

    $answers = array_map(static fn (int $qid): array => ['question_id' => $qid, 'answer' => 'right'], $serve1);
    $submit = $service->submit($student1, $quizB, $answers);
    check('answering every drawn question correctly scores 100%',
        $submit['percent'] === 100.0 && $submit['total'] === 7, json_encode($submit));

    echo "\n=== B. redraw changes the selection (§3) ===\n";

    $redraw = $draws->redraw($childB);
    check('redraw keeps the sizes (grammar 3, listening 4, total 7)',
        $redraw['counts']['grammar'] === 3 && $redraw['counts']['listening'] === 4
        && $redraw['total'] === 7, json_encode($redraw['counts']));
    check('redraw returns external codes for each drawn question',
        count($redraw['external_codes']) === 7);

    $baseline = $draws->redraw($childB)['question_ids'];
    $changed = false;
    for ($i = 0; $i < 25 && !$changed; $i++) {
        if ($draws->redraw($childB)['question_ids'] !== $baseline) {
            $changed = true;
        }
    }
    check('repeated redraws do not all coincide (a real re-draw)', $changed);

    echo "\n=== B. self-heal: a drawn question stops being servable (§3) ===\n";

    $current = $draws->ensure($childB);
    $victim = array_values(array_intersect($current, $grammarPool))[0];
    // Break the victim: give it a pending-media stem.
    Capsule::table('questions')->where('id', $victim)
        ->update(['payload' => json_encode($audioQ(), JSON_UNESCAPED_UNICODE)]);

    $healed = $draws->ensure($childB);
    check('the now-unservable question is dropped from the draw',
        !in_array($victim, $healed, true));
    check('it is replaced from the pool — still 3 grammar, 7 total',
        count($healed) === 7 && count(array_values(array_intersect($healed, $grammarPool))) === 3);
    check('the still-valid picks were kept (only the victim moved)',
        count(array_intersect($current, $healed)) === 6);

    echo "\n=== B. a pool smaller than its target contributes its whole self ===\n";

    // Break grammar down to 2 servable (target is 3).
    $servableGrammar = array_values(array_diff($grammarPool, [$victim]));
    foreach (array_slice($servableGrammar, 0, 3) as $id) {
        Capsule::table('questions')->where('id', $id)
            ->update(['payload' => json_encode($audioQ(), JSON_UNESCAPED_UNICODE)]);
    }
    check('grammar pool is now 2 (< target 3)', count($draws->pool($childB, 'grammar')) === 2);
    $shrunk = $draws->ensure($childB);
    check('the draw shrinks to the whole pool: 2 grammar + 4 listening = 6',
        count($shrunk) === 6 && count(array_values(array_intersect($shrunk, $grammarPool))) === 2,
        json_encode($shrunk));

    // ===================================================================
    echo "\n=== media route: traversal guard + streaming ===\n";

    file_put_contents($mediaTmp . '/q777-stem.mp3', 'FAKE-AUDIO-BYTES');
    $storage = new MediaStorage($mediaTmp);

    check('resolve() returns the stored file',
        @file_get_contents($storage->resolve('q777-stem.mp3')) === 'FAKE-AUDIO-BYTES');

    foreach (['../secret', '..\\secret', 'a/b', 'q1/../x', '/etc/passwd', 'q1\\..\\x', ''] as $bad) {
        try {
            $storage->resolve($bad);
            check("traversal rejected: '{$bad}'", false);
        } catch (ApiException $e) {
            check("traversal rejected: '{$bad}'", $e->errorCode === 'not_found');
        }
    }

    $controller = new MediaController($storage);
    $reqFactory = new ServerRequestFactory();
    $resFactory = new ResponseFactory();
    $authed = $reqFactory->createServerRequest('GET', '/api/v1/media/q777-stem.mp3')
        ->withAttribute(Scope::ATTRIBUTE, new Scope(1, 'student', null, $classB));

    $out = $controller->serve($authed, $resFactory->createResponse(), ['name' => 'q777-stem.mp3']);
    check('controller streams the file with the right type',
        (string) $out->getBody() === 'FAKE-AUDIO-BYTES'
        && $out->getStatusCode() === 200
        && $out->getHeaderLine('Content-Type') === 'audio/mpeg');

    try {
        $controller->serve($authed, $resFactory->createResponse(), ['name' => '../../secret']);
        check('controller refuses a traversal name', false);
    } catch (ApiException $e) {
        check('controller refuses a traversal name', $e->errorCode === 'not_found');
    }

    try {
        $controller->serve($authed, $resFactory->createResponse(), ['name' => 'q404-stem.mp3']);
        check('controller 404s a missing file', false);
    } catch (ApiException $e) {
        check('controller 404s a missing file', $e->errorCode === 'not_found');
    }

    echo "\n=== media route: upload attaches a file, delete clears it ===\n";

    $srcPath = $mediaTmp . '/src.mp3';
    file_put_contents($srcPath, 'NEW-CLIP');
    $uploaded = new UploadedFile($srcPath, 'clip.mp3', 'audio/mpeg', filesize($srcPath) ?: null, UPLOAD_ERR_OK);
    $superadmin = new Scope(1, User::ROLE_SUPERADMIN, null, null);

    $uploadReq = $reqFactory->createServerRequest('POST', '/x')
        ->withAttribute(Scope::ATTRIBUTE, $superadmin)
        ->withUploadedFiles(['file' => $uploaded]);
    $uploadOut = $controller->upload($uploadReq, $resFactory->createResponse(), ['questionId' => $uploadQ, 'part' => 'stem']);
    $uploadJson = json_decode((string) $uploadOut->getBody(), true);

    check('upload stores the file and reports servable',
        $uploadJson['servable'] === true
        && $uploadJson['media_path'] === 'media/q' . $uploadQ . '-stem.mp3'
        && is_file($mediaTmp . '/q' . $uploadQ . '-stem.mp3'));
    $afterUpload = json_decode((string) Capsule::table('questions')->where('id', $uploadQ)->value('payload'), true);
    check('the question payload now carries the media_path',
        ($afterUpload['stem']['media_path'] ?? null) === 'media/q' . $uploadQ . '-stem.mp3'
        && Question::payloadServable($afterUpload));

    $deleteReq = $reqFactory->createServerRequest('DELETE', '/x')->withAttribute(Scope::ATTRIBUTE, $superadmin);
    $deleteOut = $controller->delete($deleteReq, $resFactory->createResponse(), ['questionId' => $uploadQ, 'part' => 'stem']);
    check('delete clears the file and the media_path',
        json_decode((string) $deleteOut->getBody(), true)['servable'] === false
        && !is_file($mediaTmp . '/q' . $uploadQ . '-stem.mp3'));

    // A non-superadmin cannot attach media.
    try {
        $controller->upload(
            $reqFactory->createServerRequest('POST', '/x')
                ->withAttribute(Scope::ATTRIBUTE, new Scope(1, 'student', null, $classB))
                ->withUploadedFiles(['file' => new UploadedFile($srcPath, 'c.mp3', 'audio/mpeg', 8, UPLOAD_ERR_OK)]),
            $resFactory->createResponse(),
            ['questionId' => $uploadQ, 'part' => 'stem']
        );
        check('a student may not upload media', false);
    } catch (ApiException $e) {
        check('a student may not upload media', $e->errorCode === 'forbidden');
    }
} finally {
    $connection->rollBack();

    foreach ([$tmpDir . '/rt', $tmpDir, $mediaTmp] as $dir) {
        foreach (glob($dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }
    echo "\nTransaction rolled back, temp files removed.\n";
}

echo "{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
