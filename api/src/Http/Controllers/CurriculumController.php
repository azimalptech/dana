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
                    'title'    => $unit->title,
                    'sections' => collect($sections[$unit->id] ?? [])->map(fn ($s): array => [
                        'id'             => (int) $s->id,
                        'code'           => $s->code,
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

    public function createUnit(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->requireSuperadmin($request);
        $body = $this->body($request);
        $levelId = (int) ($body['level_id'] ?? 0);
        $number = (int) ($body['number'] ?? 0);

        if ($levelId === 0 || $number === 0) {
            throw ApiException::validation('Dereje we bölüm belgisi gerek.', 'Нужен уровень и номер юнита.');
        }

        if (Capsule::table('units')->where('level_id', $levelId)->where('number', $number)->exists()) {
            throw ApiException::validation('Bu bölüm eýýäm bar.', 'Такой юнит уже существует.');
        }

        return $this->json($response, [
            'id' => Capsule::table('units')->insertGetId([
                'level_id'   => $levelId,
                'number'     => $number,
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
                'title'          => mb_substr(trim((string) ($body['title'] ?? '')), 0, 200) ?: null,
                'sort_order'     => (int) Capsule::table('unit_sections')->where('unit_id', $unitId)->count() + 1,
                'level_position' => $position,
            ]),
        ], 201);
    }

    /** Superadmin edits anything (client, 2026-08-13): unit number/title. */
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

        Capsule::table('units')->where('id', $unit->id)->update([
            'number'     => $number,
            'sort_order' => $number,
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
            'title' => array_key_exists('title', $body)
                ? (mb_substr(trim((string) $body['title']), 0, 200) ?: null)
                : $child->title,
        ]);

        return $this->json($response, ['ok' => true]);
    }

    private function requireSuperadmin(ServerRequestInterface $request): void
    {
        $this->scope($request)->requireRole(User::ROLE_SUPERADMIN);
    }
}
