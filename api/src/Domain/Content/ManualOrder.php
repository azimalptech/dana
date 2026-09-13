<?php

declare(strict_types=1);

namespace Dana\Domain\Content;

use Dana\Http\ApiException;
use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * Hand-set display order (FR-15.16, FR-15.17).
 *
 * Three things in the course are ordered by hand — a level's units, a
 * unit's child units, and a child unit's typed sections — and all three
 * obey the same two rules, which live here so they cannot drift apart:
 *
 *  1. The WHOLE list arrives at once. Writing one row's sort_order can
 *     express "move down" only by leaving two rows sharing a number for
 *     an instant, and which one a student then saw first would come down
 *     to which id was lower.
 *
 *  2. A list that is missing an id, repeats one, or borrows one from
 *     another parent is refused outright rather than partially applied.
 */
final class ManualOrder
{
    /**
     * @param int[] $current the ids that genuinely belong to the parent
     * @param int[] $order   the ids the caller wants, in the wanted order
     */
    public static function assertComplete(
        array $current,
        array $order,
        string $messageTk,
        string $messageRu,
    ): void {
        sort($current);
        sort($order);

        if ($current !== $order) {
            throw ApiException::validation($messageTk, $messageRu);
        }
    }

    /**
     * Writes sort_order 1..N in the sequence given. Call inside a
     * transaction — half a renumber is worse than none.
     *
     * @param int[] $order
     */
    public static function renumber(string $table, array $order): void
    {
        foreach ($order as $position => $id) {
            Capsule::table($table)->where('id', $id)->update(['sort_order' => $position + 1]);
        }
    }

    /**
     * Rebuilds `unit_sections.level_position` for one level.
     *
     * It is the teaching order across the WHOLE level — "everything with
     * a lower value counts as already taught" — so it cannot be derived
     * from one unit alone, and reordering either units or child units
     * invalidates it. Recomputing the level in one pass keeps it a
     * function of the display order instead of a second, drifting truth:
     * before this, `/manage/content` sorted child units by
     * level_position while the curriculum page and the student's outline
     * sorted by sort_order, so the two panel pages could disagree with
     * each other and with the app.
     */
    public static function rebuildLevelPositions(int $levelId): void
    {
        $rows = Capsule::table('unit_sections as cu')
            ->join('units as u', 'u.id', '=', 'cu.unit_id')
            ->where('u.level_id', $levelId)
            ->orderBy('u.sort_order')
            ->orderBy('u.id')
            ->orderBy('cu.sort_order')
            ->orderBy('cu.id')
            ->pluck('cu.id');

        foreach ($rows as $position => $id) {
            Capsule::table('unit_sections')
                ->where('id', $id)
                ->update(['level_position' => $position + 1]);
        }
    }
}
