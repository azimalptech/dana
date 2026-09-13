<?php

declare(strict_types=1);

/**
 * Manual unit and child-unit order — FR-15.17.
 *
 *   C:/xampp/php/php.exe tests/curriculum_order_test.php
 *
 * The sibling of FR-15.16, one level up: the client saw a unit whose
 * child units read 6D, 6C, 6A, 6B and asked for the same reordering on
 * the curriculum page. Same contract — the whole list at once, refused
 * unless it is exactly that parent's children — plus two things that are
 * specific to this level of the tree:
 *
 *  - `units.number` and `unit_sections.code` are the xlsx import's join
 *    keys. Reordering must not touch them, or the next upload lands in
 *    the wrong unit.
 *
 *  - `unit_sections.level_position` is the teaching order across the
 *    WHOLE level. It is not derivable from one unit, so any reorder
 *    rebuilds it for the level — otherwise the three surfaces that read
 *    the tree drift apart.
 *
 * Creates its own level, units and child units, and removes exactly
 * those rows afterwards.
 */

require __DIR__ . '/../vendor/autoload.php';

use Dana\Database\Bootstrap;
use Dana\Http\ApiException;
use Dana\Http\Controllers\CurriculumController;
use Dana\Support\Config;
use Illuminate\Database\Capsule\Manager as Capsule;

$config = Config::load(dirname(__DIR__));
Bootstrap::boot($config);

const TEST_SLUG = '__curriculum_order_test__';

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

    if ($unitIds !== []) {
        Capsule::table('unit_sections')->whereIn('unit_id', $unitIds)->delete();
        Capsule::table('units')->whereIn('id', $unitIds)->delete();
    }

    Capsule::table('levels')->whereIn('id', $levelIds)->delete();
}

cleanup();

$now = date('Y-m-d H:i:s');

$levelId = (int) Capsule::table('levels')->insertGetId([
    'name' => 'Curriculum order test', 'slug' => TEST_SLUG, 'sort_order' => 998,
    'is_active' => 0, 'created_at' => $now, 'updated_at' => $now,
]);

$controller = new CurriculumController();

/** Calls a reorder endpoint the way the router would. */
function callReorder(
    CurriculumController $controller,
    string $method,
    int $id,
    array $order,
): array {
    $request = (new Slim\Psr7\Factory\ServerRequestFactory())
        ->createServerRequest('POST', "/manage/{$id}/order")
        ->withAttribute(
            Dana\Domain\Scope::ATTRIBUTE,
            new Dana\Domain\Scope(userId: 1, role: 'superadmin', centerId: null, classroomId: null)
        )
        ->withParsedBody(['order' => $order]);

    $response = $controller->{$method}($request, new Slim\Psr7\Response(), ['id' => (string) $id]);
    $response->getBody()->rewind();

    return json_decode((string) $response->getBody(), true) ?? [];
}

/** Units of the level, in display order. */
function unitOrder(int $levelId): array
{
    return Capsule::table('units')->where('level_id', $levelId)
        ->orderBy('sort_order')->orderBy('id')->pluck('name')->all();
}

/** Child units of one unit, in display order. */
function childOrder(int $unitId): array
{
    return Capsule::table('unit_sections')->where('unit_id', $unitId)
        ->orderBy('sort_order')->orderBy('id')->pluck('code')->all();
}

/** The level-wide teaching order, as "unitName/code". */
function teachingOrder(int $levelId): array
{
    return Capsule::table('unit_sections as cu')
        ->join('units as u', 'u.id', '=', 'cu.unit_id')
        ->where('u.level_id', $levelId)
        ->orderBy('cu.level_position')
        ->get(['u.name', 'cu.code'])
        ->map(fn ($r): string => $r->name . '/' . $r->code)
        ->all();
}

$units = [];
$children = [];

foreach ([['One', 1], ['Two', 2]] as $i => [$name, $number]) {
    $unitId = (int) Capsule::table('units')->insertGetId([
        'level_id' => $levelId, 'number' => $number, 'name' => $name,
        'title' => null, 'sort_order' => $number,
    ]);
    $units[$name] = $unitId;

    foreach (['A', 'B', 'C'] as $j => $code) {
        $children[$name][$code] = (int) Capsule::table('unit_sections')->insertGetId([
            'unit_id'        => $unitId,
            'code'           => $code,
            'sort_order'     => $j + 1,
            'level_position' => $i * 3 + $j + 1,
        ]);
    }
}

try {
    echo PHP_EOL . 'Before' . PHP_EOL;
    check('units start One, Two', unitOrder($levelId) === ['One', 'Two'],
        implode(', ', unitOrder($levelId)));
    check('child units start A, B, C', childOrder($units['One']) === ['A', 'B', 'C'],
        implode(', ', childOrder($units['One'])));

    echo PHP_EOL . 'Reordering the child units of one unit' . PHP_EOL;

    // The client's case: the list reads D, C, A, B and they want it sane.
    callReorder($controller, 'reorderChildUnits', $units['One'], [
        $children['One']['C'], $children['One']['A'], $children['One']['B'],
    ]);

    check('the new order is exactly what was sent',
        childOrder($units['One']) === ['C', 'A', 'B'],
        implode(', ', childOrder($units['One'])));

    check('the other unit is untouched', childOrder($units['Two']) === ['A', 'B', 'C'],
        implode(', ', childOrder($units['Two'])));

    check('the level-wide teaching order followed',
        teachingOrder($levelId) === ['One/C', 'One/A', 'One/B', 'Two/A', 'Two/B', 'Two/C'],
        implode(' ', teachingOrder($levelId)));

    echo PHP_EOL . 'Reordering the units' . PHP_EOL;

    callReorder($controller, 'reorderUnits', $levelId, [$units['Two'], $units['One']]);

    check('units swapped', unitOrder($levelId) === ['Two', 'One'],
        implode(', ', unitOrder($levelId)));

    check('and the teaching order followed the units too',
        teachingOrder($levelId) === ['Two/A', 'Two/B', 'Two/C', 'One/C', 'One/A', 'One/B'],
        implode(' ', teachingOrder($levelId)));

    echo PHP_EOL . 'The xlsx join keys must NOT move' . PHP_EOL;

    check('units.number is unchanged',
        Capsule::table('units')->where('id', $units['One'])->value('number') == 1
        && Capsule::table('units')->where('id', $units['Two'])->value('number') == 2);

    check('unit_sections.code is unchanged',
        Capsule::table('unit_sections')->where('id', $children['One']['A'])->value('code') === 'A');

    echo PHP_EOL . 'A partial list is refused' . PHP_EOL;

    foreach ([
        'missing a child'        => [$children['One']['C'], $children['One']['A']],
        'a repeated id'          => [$children['One']['C'], $children['One']['C'], $children['One']['A']],
        "another unit's child"   => [$children['One']['C'], $children['One']['A'], $children['Two']['B']],
        'an empty list'          => [],
    ] as $label => $bad) {
        $thrown = null;
        try {
            callReorder($controller, 'reorderChildUnits', $units['One'], $bad);
        } catch (ApiException $e) {
            $thrown = $e;
        }
        check($label . ' is rejected', $thrown !== null);
    }

    check('and nothing moved', childOrder($units['One']) === ['C', 'A', 'B'],
        implode(', ', childOrder($units['One'])));

    $thrown = null;
    try {
        callReorder($controller, 'reorderUnits', $levelId, [$units['One']]);
    } catch (ApiException $e) {
        $thrown = $e;
    }
    check('a short unit list is rejected too', $thrown !== null);

    echo PHP_EOL . 'Unknown parents' . PHP_EOL;

    foreach ([['reorderUnits', 99999999], ['reorderChildUnits', 99999999]] as [$method, $id]) {
        $thrown = null;
        try {
            callReorder($controller, $method, $id, []);
        } catch (ApiException $e) {
            $thrown = $e;
        }
        check("{$method} on a missing parent is 404", $thrown !== null && $thrown->status === 404);
    }
} finally {
    cleanup();
}

echo PHP_EOL . "  {$pass} passed, {$fail} failed" . PHP_EOL;

exit($fail === 0 ? 0 : 1);
