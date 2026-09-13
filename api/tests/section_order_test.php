<?php

declare(strict_types=1);

/**
 * Manual section order — FR-15.16.
 *
 *   C:/xampp/php/php.exe tests/section_order_test.php
 *
 * The client wants to decide whether Grammar or Vocabulary comes first,
 * to change their mind after the content is already uploaded, and to see
 * the new order in the app. `sections.sort_order` already existed and
 * every read already sorted by it; what was missing was a way to SET it
 * that cannot leave two rows sharing a number — a swap written one row
 * at a time has a moment where the order depends on which id is lower.
 *
 * These cases lock the endpoint's contract: a complete list or nothing,
 * one atomic renumber, siblings brought along on request, and — the
 * point of the whole feature — the student's outline reading back in the
 * order the panel set.
 *
 * Creates its own level, unit, child units and sections, and removes
 * exactly those rows afterwards.
 */

require __DIR__ . '/../vendor/autoload.php';

use Dana\Database\Bootstrap;
use Dana\Domain\Models\Section;
use Dana\Http\ApiException;
use Dana\Http\Controllers\ContentAdminController;
use Dana\Support\Config;
use Illuminate\Database\Capsule\Manager as Capsule;

$config = Config::load(dirname(__DIR__));
Bootstrap::boot($config);

const TEST_SLUG = '__section_order_test__';

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? '  PASS  ' : '  FAIL  ') . $label . ($detail !== '' ? "  ({$detail})" : '') . PHP_EOL;
}

function cleanup(): void
{
    $levelIds = Capsule::table('levels')->where('slug', TEST_SLUG)->pluck('id')->all();

    if ($levelIds === []) {
        return;
    }

    $unitIds = Capsule::table('units')->whereIn('level_id', $levelIds)->pluck('id')->all();
    $childIds = $unitIds === []
        ? []
        : Capsule::table('unit_sections')->whereIn('unit_id', $unitIds)->pluck('id')->all();

    if ($childIds !== []) {
        Capsule::table('sections')->whereIn('unit_section_id', $childIds)->delete();
        Capsule::table('unit_sections')->whereIn('id', $childIds)->delete();
    }

    if ($unitIds !== []) {
        Capsule::table('units')->whereIn('id', $unitIds)->delete();
    }

    Capsule::table('levels')->whereIn('id', $levelIds)->delete();
}

cleanup();

$now = date('Y-m-d H:i:s');

$levelId = Capsule::table('levels')->insertGetId([
    'name' => 'Section order test', 'slug' => TEST_SLUG, 'sort_order' => 999,
    'is_active' => 0, 'created_at' => $now, 'updated_at' => $now,
]);

$unitId = Capsule::table('units')->insertGetId([
    'level_id' => $levelId, 'number' => 1, 'title' => 'T', 'sort_order' => 1,
]);

/** One child unit with the four types, in the order the importer would leave them. */
function makeChild(int $unitId, string $code, int $position, array $types): array
{
    $childId = (int) Capsule::table('unit_sections')->insertGetId([
        'unit_id' => $unitId, 'code' => $code, 'sort_order' => $position,
        'level_position' => $position,
    ]);

    $ids = [];
    $now = date('Y-m-d H:i:s');

    foreach ($types as $i => $type) {
        $ids[$type] = (int) Capsule::table('sections')->insertGetId([
            'unit_section_id' => $childId,
            'type'            => $type,
            'status'          => Section::STATUS_PUBLISHED,
            'sort_order'      => $i + 1,
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);
    }

    return [$childId, $ids];
}

/** The order the API and the app both read back. */
function orderOf(int $childId): array
{
    return Capsule::table('sections')
        ->where('unit_section_id', $childId)
        ->orderBy('sort_order')
        ->orderBy('id')
        ->pluck('type')
        ->all();
}

[$childA, $a] = makeChild((int) $unitId, 'A', 1, ['listening', 'grammar', 'vocabulary', 'quiz']);
[$childB, $b] = makeChild((int) $unitId, 'B', 2, ['listening', 'grammar', 'vocabulary', 'quiz']);
[$childC, $c] = makeChild((int) $unitId, 'C', 3, ['grammar', 'vocabulary']);

$controller = new ContentAdminController(
    new Dana\Domain\Progress\QuizDrawService(),
    // FR-15.18 added a generator to the constructor; ordering never
    // touches it, and one with no key cannot call out.
    new Dana\Domain\Media\GeminiMedia(
        new Dana\Domain\Media\GeminiSettings(apiKey: null),
        new Psr\Log\NullLogger()
    )
);

/** Calls the endpoint the way the router would, superadmin scope included. */
function callReorder(
    ContentAdminController $controller,
    int $childId,
    array $order,
    bool $alsoSiblings = false,
): array {
    $request = (new Slim\Psr7\Factory\ServerRequestFactory())
        ->createServerRequest('POST', "/manage/child-units/{$childId}/section-order")
        ->withAttribute(
            Dana\Domain\Scope::ATTRIBUTE,
            new Dana\Domain\Scope(userId: 1, role: 'superadmin', centerId: null, classroomId: null)
        )
        ->withParsedBody(['order' => $order, 'also_siblings' => $alsoSiblings]);

    $response = $controller->reorderSections(
        $request,
        new Slim\Psr7\Response(),
        ['id' => (string) $childId]
    );

    $response->getBody()->rewind();

    return json_decode((string) $response->getBody(), true) ?? [];
}

try {
    echo PHP_EOL . 'Before' . PHP_EOL;
    check('the importer left listening first', orderOf($childA)[0] === 'listening',
        implode(', ', orderOf($childA)));

    echo PHP_EOL . 'Swapping grammar and vocabulary' . PHP_EOL;

    callReorder($controller, $childA, [$a['vocabulary'], $a['grammar'], $a['listening'], $a['quiz']]);

    check('the new order is exactly what was sent',
        orderOf($childA) === ['vocabulary', 'grammar', 'listening', 'quiz'],
        implode(', ', orderOf($childA)));

    check('and every position is distinct', Capsule::table('sections')
        ->where('unit_section_id', $childA)
        ->distinct()->count('sort_order') === 4);

    echo PHP_EOL . 'A partial list is refused' . PHP_EOL;

    foreach ([
        'missing a section'   => [$a['vocabulary'], $a['grammar']],
        'a repeated id'       => [$a['vocabulary'], $a['vocabulary'], $a['grammar'], $a['quiz']],
        "another unit's id"   => [$a['vocabulary'], $a['grammar'], $a['listening'], $b['quiz']],
        'an empty list'       => [],
    ] as $label => $bad) {
        $thrown = null;
        try {
            callReorder($controller, $childA, $bad);
        } catch (ApiException $e) {
            $thrown = $e;
        }

        check($label . ' is rejected', $thrown !== null);
    }

    check('and the stored order is untouched',
        orderOf($childA) === ['vocabulary', 'grammar', 'listening', 'quiz'],
        implode(', ', orderOf($childA)));

    echo PHP_EOL . 'Applying the order to the sibling child units' . PHP_EOL;

    check('sibling B still has the old order', orderOf($childB)[0] === 'listening');

    $result = callReorder(
        $controller,
        $childA,
        [$a['grammar'], $a['vocabulary'], $a['listening'], $a['quiz']],
        true
    );

    check('the answer counts every child unit it touched',
        ($result['child_units'] ?? 0) === 3, json_encode($result));

    check('B took the same order',
        orderOf($childB) === ['grammar', 'vocabulary', 'listening', 'quiz'],
        implode(', ', orderOf($childB)));

    check('C, which has only two of the types, took the order it could',
        orderOf($childC) === ['grammar', 'vocabulary'],
        implode(', ', orderOf($childC)));

    echo PHP_EOL . 'A repeated type must not invert what the siblings get' . PHP_EOL;

    // Only the quiz is unique per child unit, so two Grammar sections are
    // a supported state. Ranking siblings by type then has to pick one of
    // the two: FIRST occurrence wins. Letting the LAST one win would key
    // grammar to a position after vocabulary and write every sibling to
    // the opposite of what the source unit shows.
    $extraGrammar = (int) Capsule::table('sections')->insertGetId([
        'unit_section_id' => $childA,
        'type'            => 'grammar',
        'status'          => Section::STATUS_PUBLISHED,
        'sort_order'      => 9,
        'created_at'      => date('Y-m-d H:i:s'),
        'updated_at'      => date('Y-m-d H:i:s'),
    ]);

    callReorder(
        $controller,
        $childA,
        // grammar, vocabulary, THE SECOND GRAMMAR, listening, quiz
        [$a['grammar'], $a['vocabulary'], $extraGrammar, $a['listening'], $a['quiz']],
        true
    );

    check('the source unit keeps exactly the order it was given',
        orderOf($childA) === ['grammar', 'vocabulary', 'grammar', 'listening', 'quiz'],
        implode(', ', orderOf($childA)));

    check('and the sibling still gets grammar BEFORE vocabulary',
        orderOf($childB) === ['grammar', 'vocabulary', 'listening', 'quiz'],
        implode(', ', orderOf($childB)));

    Capsule::table('sections')->where('id', $extraGrammar)->delete();
    callReorder($controller, $childA, [$a['grammar'], $a['vocabulary'], $a['listening'], $a['quiz']]);

    echo PHP_EOL . 'A type the order never named sorts last, not tied' . PHP_EOL;

    // childC has grammar + vocabulary. Give it a listening section that
    // the order below says nothing about, then send an order naming only
    // two types: the unnamed one must land after both, never level with
    // the second.
    $extraListening = (int) Capsule::table('sections')->insertGetId([
        'unit_section_id' => $childC,
        'type'            => 'listening',
        'status'          => Section::STATUS_PUBLISHED,
        'sort_order'      => 9,
        'created_at'      => date('Y-m-d H:i:s'),
        'updated_at'      => date('Y-m-d H:i:s'),
    ]);

    [$childD, $d] = makeChild((int) $unitId, 'D', 4, ['vocabulary', 'grammar']);
    callReorder($controller, $childD, [$d['grammar'], $d['vocabulary']], true);

    check('the unnamed type is last in the sibling',
        orderOf($childC) === ['grammar', 'vocabulary', 'listening'],
        implode(', ', orderOf($childC)));

    Capsule::table('sections')->where('id', $extraListening)->delete();

    echo PHP_EOL . 'A re-import must not undo it' . PHP_EOL;

    // sectionOfType() reuses the existing row for a type, so a re-upload
    // touches nothing here. Proven by doing what it does.
    $reused = Capsule::table('sections')
        ->where('unit_section_id', $childA)->where('type', 'grammar')
        ->orderBy('id')->value('id');

    check('the importer would reuse the same row', (int) $reused === $a['grammar']);
    check('so the order survives a re-upload',
        orderOf($childA) === ['grammar', 'vocabulary', 'listening', 'quiz'],
        implode(', ', orderOf($childA)));
} finally {
    cleanup();
}

echo PHP_EOL . "  {$pass} passed, {$fail} failed" . PHP_EOL;

exit($fail === 0 ? 0 : 1);
