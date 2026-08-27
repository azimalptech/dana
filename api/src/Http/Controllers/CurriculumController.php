<?php

declare(strict_types=1);

namespace Dana\Http\Controllers;

use Dana\Domain\Models\User;
use Dana\Http\ApiException;
use Illuminate\Database\Capsule\Manager as Capsule;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Curriculum structure — FR-3.3 (levels), FR-3.12 (units and sections).
 *
 * Books are out of the product (FR-14.5): no book sets, no page ranges,
 * no uploads. The superadmin defines levels, units and child units;
 * content attaches to typed sections via authoring or xlsx import.
 */
final class CurriculumController extends Controller
{
    /** The whole tree, for the curriculum screen. */
    public function tree(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->requireSuperadmin($request);

        $levels = Capsule::table('levels')->orderBy('sort_order')->get();
        $units = Capsule::table('units')->orderBy('sort_order')->get()->groupBy('level_id');
        $sections = Capsule::table('unit_sections')->orderBy('sort_order')->get()->groupBy('unit_id');

        return $this->json($response, [
            'levels' => $levels->map(fn ($level): array => [
                'id'        => (int) $level->id,
                'name'      => $level->name,
                'is_active' => (bool) $level->is_active,
                'units' => collect($units[$level->id] ?? [])->map(fn ($unit): array => [
                    'id'       => (int) $unit->id,
                    'number'   => (int) $unit->number,
                    // Manual naming (client, 2026-08-20): when set, this
                    // IS the unit's identity, verbatim. Null on legacy
                    // rows — the panel keeps composing "Юнит {number}".
                    'name'     => $unit->name,
                    'title'    => $unit->title,
                    'sections' => collect($sections[$unit->id] ?? [])->map(fn ($s): array => [
                        'id'             => (int) $s->id,
                        'code'           => $s->code,
                        // Same contract as units.name: verbatim display
                        // label when set, "{number}-{code}" fallback when
                        // null.
                        'label'          => $s->label,
                        'title'          => $s->title,
                        'level_position' => (int) $s->level_position,
                    ])->values()->all(),
                ])->values()->all(),
            ])->all(),
        ]);
    }

    // ----------------------------------------------------------- levels

    public function createLevel(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->requireSuperadmin($request);
        $name = trim((string) ($this->body($request)['name'] ?? ''));

        if ($name === '') {
            throw ApiException::validation('Derejäniň adyny giriziň.', 'Введите название уровня.');
        }

        $slug = strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '-', $name), '-'));

        if ($slug === '' || Capsule::table('levels')->where('slug', $slug)->exists()) {
            throw ApiException::validation(
                'Bu dereje eýýäm bar.',
                'Такой уровень уже существует.'
            );
        }

        $now = date('Y-m-d H:i:s');

        $levelId = Capsule::table('levels')->insertGetId([
            'name'       => mb_substr($name, 0, 80),
            'slug'       => $slug,
            'sort_order' => (int) Capsule::table('levels')->max('sort_order') + 1,
            'is_active'  => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Books are out of the product (FR-14.5): a level no longer carries
        // a book set. Content attaches to its units' typed sections.
        return $this->json($response, ['id' => $levelId], 201);
    }

    // ------------------------------------------------ units and sections

    /**
     * Manual naming (client decision 2026-08-20: "no auto numbers, I
     * name units completely myself"): the caller sends a free-text NAME
     * and that name is the unit's identity, verbatim. `number` is now
     * purely internal — derived as max+1 within the level so the
     * uq_unit(level_id, number) key and the sort order keep working —
     * and a client-sent number is deliberately ignored.
     */
    public function createUnit(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->requireSuperadmin($request);
        $body = $this->body($request);
        $levelId = (int) ($body['level_id'] ?? 0);
        $name = trim((string) ($body['name'] ?? ''));

        if ($levelId === 0 || $name === '') {
            throw ApiException::validation('Dereje we bölümiň ady gerek.', 'Нужен уровень и название юнита.');
        }

        if (!Capsule::table('levels')->where('id', $levelId)->exists()) {
            throw ApiException::notFound();
        }

        $number = (int) Capsule::table('units')->where('level_id', $levelId)->max('number') + 1;

        return $this->json($response, [
            'id' => Capsule::table('units')->insertGetId([
                'level_id'   => $levelId,
                'number'     => $number,
                'name'       => mb_substr($name, 0, 120),
                'title'      => mb_substr(trim((string) ($body['title'] ?? '')), 0, 200) ?: null,
                'sort_order' => $number,
            ]),
        ], 201);
    }

    public function createSection(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->requireSuperadmin($request);
        $body = $this->body($request);
        $unitId = (int) ($body['unit_id'] ?? 0);
        $code = trim((string) ($body['code'] ?? ''));

        if ($unitId === 0 || $code === '') {
            throw ApiException::validation('Bölüm we kody gerek.', 'Нужен юнит и код раздела.');
        }

        if (Capsule::table('unit_sections')->where('unit_id', $unitId)->where('code', $code)->exists()) {
            throw ApiException::validation('Bu bölümçe eýýäm bar.', 'Такой раздел уже существует.');
        }

        // Manual naming (client, 2026-08-20): an optional free-text label
        // shown verbatim instead of the "{number}-{code}" composition.
        // `code` stays required — it is the uniqueness and xlsx join key.
        $label = mb_substr(trim((string) ($body['label'] ?? '')), 0, 32) ?: null;

        $levelId = Capsule::table('units')->where('id', $unitId)->value('level_id');

        // level_position is the teaching order across the whole level and
        // drives the grammar/vocabulary ceilings (FR-4.6, FR-4.7), so a
        // new section goes to the end of the level, not of its unit.
        $position = (int) Capsule::table('unit_sections')
            ->join('units', 'units.id', '=', 'unit_sections.unit_id')
            ->where('units.level_id', $levelId)
            ->max('unit_sections.level_position') + 1;

        return $this->json($response, [
            'id' => Capsule::table('unit_sections')->insertGetId([
                'unit_id'        => $unitId,
                'code'           => mb_substr($code, 0, 8),
                'label'          => $label,
                'title'          => mb_substr(trim((string) ($body['title'] ?? '')), 0, 200) ?: null,
                'sort_order'     => (int) Capsule::table('unit_sections')->where('unit_id', $unitId)->count() + 1,
                'level_position' => $position,
            ]),
        ], 201);
    }

    /**
     * Superadmin edits anything (client, 2026-08-13): unit name/title.
     * `number` stays accepted for REORDERING only (it is the sort key),
     * never required — identity is the free-text name (2026-08-20).
     */
    public function updateUnit(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->requireSuperadmin($request);
        $body = $this->body($request);
        $unit = Capsule::table('units')->where('id', (int) $args['id'])->first();

        if ($unit === null) {
            throw ApiException::notFound();
        }

        $number = (int) ($body['number'] ?? $unit->number);
        if ($number < 1) {
            throw ApiException::validation('Bölüm belgisi nädogry.', 'Неверный номер юнита.');
        }

        if ($number !== (int) $unit->number && Capsule::table('units')
            ->where('level_id', $unit->level_id)
            ->where('number', $number)
            ->exists()) {
            throw ApiException::validation('Bu bölüm eýýäm bar.', 'Такой юнит уже существует.');
        }

        // Rename: the name is the display identity, so an explicit rename
        // to nothing would leave the unit unidentifiable — refused rather
        // than silently falling back to the legacy number composition.
        $name = $unit->name;
        if (array_key_exists('name', $body)) {
            $name = mb_substr(trim((string) $body['name']), 0, 120);
            if ($name === '') {
                throw ApiException::validation('Bölümiň adyny giriziň.', 'Введите название юнита.');
            }
        }

        Capsule::table('units')->where('id', $unit->id)->update([
            'number'     => $number,
            'sort_order' => $number,
            'name'       => $name,
            'title'      => array_key_exists('title', $body)
                ? (mb_substr(trim((string) $body['title']), 0, 200) ?: null)
                : $unit->title,
        ]);

        return $this->json($response, ['ok' => true]);
    }

    /** Superadmin edits anything: child-unit code/title. */
    public function updateChildUnit(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->requireSuperadmin($request);
        $body = $this->body($request);
        $child = Capsule::table('unit_sections')->where('id', (int) $args['id'])->first();

        if ($child === null) {
            throw ApiException::notFound();
        }

        $code = mb_substr(trim((string) ($body['code'] ?? $child->code)), 0, 8);
        if ($code === '') {
            throw ApiException::validation('Kody giriziň.', 'Введите код раздела.');
        }

        if ($code !== $child->code && Capsule::table('unit_sections')
            ->where('unit_id', $child->unit_id)
            ->where('code', $code)
            ->exists()) {
            throw ApiException::validation('Bu bölümçe eýýäm bar.', 'Такой раздел уже существует.');
        }

        Capsule::table('unit_sections')->where('id', $child->id)->update([
            'code'  => $code,
            // The label is optional — clearing it falls back to the
            // "{number}-{code}" composition, unlike units.name.
            'label' => array_key_exists('label', $body)
                ? (mb_substr(trim((string) $body['label']), 0, 32) ?: null)
                : $child->label,
            'title' => array_key_exists('title', $body)
                ? (mb_substr(trim((string) $body['title']), 0, 200) ?: null)
                : $child->title,
        ]);

        return $this->json($response, ['ok' => true]);
    }

    // ----------------------------------------------------------- deletes

    /**
     * DELETE /manage/levels/{id} — "I want to be able to delete
     * everything" (client, 2026-08).
     *
     * Refused outright while any classroom references the level:
     * dissolving a running (or even closed) course's level from under it
     * is FR-1.14's job — closing a course is an explicit, name-confirmed
     * act with an export — and level deletion must not become a way
     * around it. force=1 applies to student ATTEMPTS under the level
     * only, never to the classroom refusal.
     *
     * The cascade below the level: child units (unit_sections) cascade
     * their typed sections -> exercise_sets -> questions ->
     * attempt_answers / quiz_draws, vocabulary_items ->
     * student_bookmarks, grammar_explanations, section_attempts ->
     * attempt_answers, and student_section_stats — all enforced by the
     * DB (ON DELETE CASCADE, verified against the live schema).
     * units.level_id and unit_sections.unit_id are RESTRICT, so those
     * two levels of the tree are deleted explicitly, children first.
     */
    public function deleteLevel(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->requireSuperadmin($request);
        $level = Capsule::table('levels')->where('id', (int) $args['id'])->first();

        if ($level === null) {
            throw ApiException::notFound();
        }

        $classrooms = Capsule::table('classrooms')->where('level_id', $level->id)->count();

        if ($classrooms > 0) {
            throw ApiException::validation(
                "Bu derejäni {$classrooms} synp ulanýar — öňürti şol kurslary ýapyň (kursy ýapmak aýratyn, tassyklamaly amal).",
                "Этот уровень используют классы ({$classrooms}) — сначала завершите эти курсы: закрытие курса (FR-1.14) — отдельное подтверждаемое действие."
            );
        }

        $unitIds = Capsule::table('units')->where('level_id', $level->id)->pluck('id')->all();
        $childIds = Capsule::table('unit_sections')->whereIn('unit_id', $unitIds)->pluck('id')->all();

        $attempts = $this->attemptsUnderChildUnits($childIds);
        $this->refuseUnlessForced($request, $attempts);

        Capsule::connection()->transaction(function () use ($level, $unitIds, $childIds): void {
            // Children first: unit_sections cascade all content and
            // progress beneath them; units/levels are RESTRICT-linked.
            Capsule::table('unit_sections')->whereIn('id', $childIds)->delete();
            // Books are out of the product (FR-14.5) but dormant rows
            // still FK-RESTRICT the level — they go with it.
            Capsule::table('book_sets')->where('level_id', $level->id)->delete();
            Capsule::table('books')->where('level_id', $level->id)->delete();
            Capsule::table('units')->whereIn('id', $unitIds)->delete();
            Capsule::table('levels')->where('id', $level->id)->delete();
        });

        $this->audit($request, 'level.deleted', (int) $level->id, 'level', [
            'name'             => $level->name,
            'units_deleted'    => count($unitIds),
            'attempts_deleted' => $attempts,
        ]);

        return $this->json($response, ['ok' => true, 'attempts_deleted' => $attempts]);
    }

    /**
     * DELETE /manage/units/{id} — a PARENT unit and everything under it:
     * child units -> typed sections -> sets/questions/vocab/grammar ->
     * attempts/progress rows. Same contract as the typed-section delete
     * (ContentAdminController::deleteSection): content nobody attempted
     * goes quietly; attempted content needs force=1.
     */
    public function deleteUnit(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->requireSuperadmin($request);
        $unit = Capsule::table('units')->where('id', (int) $args['id'])->first();

        if ($unit === null) {
            throw ApiException::notFound();
        }

        $childIds = Capsule::table('unit_sections')->where('unit_id', $unit->id)->pluck('id')->all();

        $attempts = $this->attemptsUnderChildUnits($childIds);
        $this->refuseUnlessForced($request, $attempts);

        Capsule::connection()->transaction(function () use ($unit, $childIds): void {
            // unit_sections.unit_id is ON DELETE RESTRICT — the children
            // must go first, and each cascades its whole subtree.
            Capsule::table('unit_sections')->whereIn('id', $childIds)->delete();
            Capsule::table('units')->where('id', $unit->id)->delete();
        });

        $this->audit($request, 'unit.deleted', (int) $unit->id, 'unit', [
            'name'             => $unit->name ?? ('Unit ' . $unit->number),
            'attempts_deleted' => $attempts,
        ]);

        return $this->json($response, ['ok' => true, 'attempts_deleted' => $attempts]);
    }

    /**
     * DELETE /manage/sections/{id} — one CHILD unit (curriculum row).
     * A single row delete: everything beneath it — typed sections, sets,
     * questions, vocabulary, grammar, attempts, stats, bookmarks, quiz
     * draws — cascades in the DB (verified against the live schema).
     */
    public function deleteChildUnit(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->requireSuperadmin($request);
        $child = Capsule::table('unit_sections')->where('id', (int) $args['id'])->first();

        if ($child === null) {
            throw ApiException::notFound();
        }

        $attempts = $this->attemptsUnderChildUnits([(int) $child->id]);
        $this->refuseUnlessForced($request, $attempts);

        Capsule::connection()->transaction(function () use ($child): void {
            Capsule::table('unit_sections')->where('id', $child->id)->delete();
        });

        $this->audit($request, 'child_unit.deleted', (int) $child->id, 'unit_section', [
            'code'             => $child->code,
            'label'            => $child->label,
            'attempts_deleted' => $attempts,
        ]);

        return $this->json($response, ['ok' => true, 'attempts_deleted' => $attempts]);
    }

    // ---------------------------------------------------------- helpers

    /** How many student attempts live under these child units. */
    private function attemptsUnderChildUnits(array $childUnitIds): int
    {
        if ($childUnitIds === []) {
            return 0;
        }

        return Capsule::table('section_attempts as a')
            ->join('sections as sec', 'sec.id', '=', 'a.section_id')
            ->whereIn('sec.unit_section_id', $childUnitIds)
            ->count();
    }

    /**
     * The attempts_exist gate, mirrored 1:1 from
     * ContentAdminController::deleteSection — attempts are the student's
     * record (FR-13.6), so destroying them takes an explicit force=1.
     */
    private function refuseUnlessForced(ServerRequestInterface $request, int $attempts): void
    {
        $force = (string) ($request->getQueryParams()['force'] ?? '') === '1';

        if ($attempts > 0 && !$force) {
            throw new ApiException(
                'attempts_exist',
                "Bu ýerde okuwçylaryň {$attempts} synanyşygy bar. Pozulsa, olar hem pozular.",
                "Здесь есть {$attempts} попыток учеников. Удаление удалит и их.",
                409
            );
        }
    }

    /** One audit row per destructive structural change (FR-1.12). */
    private function audit(
        ServerRequestInterface $request,
        string $action,
        int $entityId,
        string $entityType,
        ?array $meta = null,
    ): void {
        Capsule::table('audit_log')->insert([
            'actor_id'    => $this->scope($request)->userId,
            'action'      => $action,
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'ip'          => $request->getServerParams()['REMOTE_ADDR'] ?? null,
            'meta'        => $meta === null ? null : json_encode($meta, JSON_UNESCAPED_UNICODE),
            'created_at'  => date('Y-m-d H:i:s'),
        ]);
    }

    private function requireSuperadmin(ServerRequestInterface $request): void
    {
        $this->scope($request)->requireRole(User::ROLE_SUPERADMIN);
    }
}
