<?php

declare(strict_types=1);

namespace Dana\Http\Controllers;

use Dana\Domain\Content\QuestionPayload;
use Dana\Domain\Models\ExerciseSet;
use Dana\Domain\Models\Section;
use Dana\Domain\Models\User;
use Dana\Domain\Progress\QuizDrawService;
use Dana\Http\ApiException;
use Illuminate\Database\Capsule\Manager as Capsule;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Superadmin content authoring against the §13 model: a CHILD UNIT
 * (1A, 1B…) holds TYPED SECTIONS (grammar / vocabulary / listening /
 * quiz, FR-13.2), content attaches to a section, and the section's
 * status is the ONLY visibility gate (FR-13.20) — grammar has no status
 * of its own any more.
 *
 * Content is authored manually, 1:1 from the workbook (client decision
 * 2026-08-07); all five exercise types including fill_letter_space
 * (FR-13.21) are available.
 *
 * Two rules run through everything here:
 *
 *  - **Question deletes are soft.** attempt_answers cascades from
 *    `questions`, so hard-deleting an answered question would silently
 *    destroy the per-question review material teachers rely on
 *    (FR-12.10). Completed percentages live on section_attempts and
 *    survive either way.
 *
 *  - **Section deletes warn.** section_attempts cascades from
 *    `sections`; deleting an attempted section destroys real student
 *    history, so it is refused until the caller repeats it with
 *    force=1 (FR-13.6 — attempts are the student's record).
 */
final class ContentAdminController extends Controller
{
    public function __construct(private readonly QuizDrawService $quizDraws)
    {
    }

    // ----------------------------------------------- child unit -> sections

    /**
     * Everything the editor needs for one child unit: its typed sections
     * with their content, drafts included. The quiz section carries a
     * read-only preview of the sibling questions it would serve (FR-13.4).
     */
    public function childUnitSections(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->requireSuperadmin($request);
        $childUnitId = (int) $args['id'];

        $childUnit = Capsule::table('unit_sections as cu')
            ->join('units as u', 'u.id', '=', 'cu.unit_id')
            ->join('levels as l', 'l.id', '=', 'u.level_id')
            ->where('cu.id', $childUnitId)
            ->select(['cu.id', 'cu.code', 'cu.label as cu_label', 'cu.title', 'u.number as unit_number', 'l.name as level'])
            ->first();

        if ($childUnit === null) {
            throw ApiException::notFound();
        }

        $sections = Capsule::table('sections')
            ->where('unit_section_id', $childUnitId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $sectionIds = $sections->pluck('id')->all();

        $attempts = Capsule::table('section_attempts')
            ->whereIn('section_id', $sectionIds)
            ->selectRaw('section_id, COUNT(*) AS n')
            ->groupBy('section_id')
            ->pluck('n', 'section_id');

        $sets = Capsule::table('exercise_sets')
            ->whereIn('section_id', $sectionIds)
            ->orderBy('sort_order')
            ->get()
            ->groupBy('section_id');

        $questions = Capsule::table('questions')
            ->whereIn('exercise_set_id', $sets->flatten()->pluck('id')->all())
            ->where('is_active', 1)
            ->orderBy('sort_order')
            ->get()
            ->groupBy('exercise_set_id');

        $vocabulary = Capsule::table('vocabulary_items')
            ->whereIn('section_id', $sectionIds)
            ->orderBy('sort_order')
            ->get()
            ->groupBy('section_id');

        $grammar = Capsule::table('grammar_explanations')
            ->whereIn('section_id', $sectionIds)
            ->get()
            ->keyBy('section_id');

        return $this->json($response, [
            'child_unit' => [
                'id'    => (int) $childUnit->id,
                // Explicit label wins verbatim (manual naming, 2026-08-20).
                'label' => $childUnit->cu_label ?? ($childUnit->unit_number . $childUnit->code),
                'title' => $childUnit->title,
                'level' => $childUnit->level,
            ],
            'sections' => $sections->map(function ($section) use (
                $attempts, $sets, $questions, $vocabulary, $grammar, $childUnitId
            ): array {
                $id = (int) $section->id;

                $row = [
                    'id'         => $id,
                    'type'       => $section->type,
                    'status'     => $section->status,
                    'title_tk'   => $section->title_tk,
                    'title_ru'   => $section->title_ru,
                    'sort_order' => (int) $section->sort_order,
                    'attempts'   => (int) ($attempts[$id] ?? 0),
                ];

                if ($section->type === Section::TYPE_VOCABULARY) {
                    $row['vocabulary'] = collect($vocabulary[$id] ?? [])->map(fn ($v): array => [
                        'id'             => (int) $v->id,
                        'term_en'        => $v->term_en,
                        'part_of_speech' => $v->part_of_speech,
                        'ipa'            => $v->ipa,
                        'translation_tk' => $v->translation_tk,
                        'translation_ru' => $v->translation_ru,
                        'example_en'     => $v->example_en,
                    ])->values()->all();
                }

                if ($section->type === Section::TYPE_GRAMMAR) {
                    $g = $grammar[$id] ?? null;
                    $row['grammar'] = $g === null ? null : [
                        'title_tk' => $g->title_tk,
                        'title_ru' => $g->title_ru,
                        'body_tk'  => $g->body_tk,
                        'body_ru'  => $g->body_ru,
                        'examples' => json_decode((string) $g->examples, true) ?? [],
                    ];
                }

                if ($section->type === Section::TYPE_QUIZ) {
                    $row['quiz_preview'] = $this->quizPreview($childUnitId);

                    // The «Состав квиза» editor was blind without these:
                    // the targets live on unit_sections and the panel got
                    // nothing back, so it rendered 0/0/0 over a live
                    // 7/8/5 draw and «Сохранить цели» read as a no-op.
                    $row['quiz_targets'] = $this->quizDraws->targets($childUnitId);
                    $row['quiz_pools'] = $this->quizPools($childUnitId);
                    $row['quiz_draw'] = $this->quizDrawCodes($childUnitId);
                } else {
                    $row['sets'] = collect($sets[$id] ?? [])->map(fn ($set): array => [
                        'id'              => (int) $set->id,
                        'type'            => $set->type,
                        'title_tk'        => $set->title_tk,
                        'title_ru'        => $set->title_ru,
                        'instructions_tk' => $set->instructions_tk,
                        'instructions_ru' => $set->instructions_ru,
                        'status'          => $set->status,
                        'pair_mode'       => $set->pair_mode,
                        'input_mode'      => $set->input_mode,
                        'questions'       => collect($questions[$set->id] ?? [])->map(fn ($q): array => [
                            'id'            => (int) $q->id,
                            'sort_order'    => (int) $q->sort_order,
                            'question_type' => $q->question_type,
                            'media_path'    => $q->media_path,
                            'quiz_eligible' => (bool) $q->quiz_eligible,
                            'prompt_tk'     => $q->prompt_tk,
                            'prompt_ru'     => $q->prompt_ru,
                            'payload'       => json_decode((string) $q->payload, true),
                        ])->values()->all(),
                    ])->values()->all();
                }

                return $row;
            })->values()->all(),
        ]);
    }

    /**
     * FR-13.2: the superadmin adds exactly the sections a child unit
     * needs — no placeholders. At most ONE quiz per child unit, enforced
     * here because MariaDB 10.4 cannot (008_redesign.sql).
     */
    public function createSection(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->requireSuperadmin($request);
        $childUnitId = (int) $args['id'];
        $body = $this->body($request);

        if (!Capsule::table('unit_sections')->where('id', $childUnitId)->exists()) {
            throw ApiException::notFound();
        }

        $type = (string) ($body['type'] ?? '');
        $allowed = [
            Section::TYPE_GRAMMAR,
            Section::TYPE_VOCABULARY,
            Section::TYPE_LISTENING,
            Section::TYPE_QUIZ,
        ];

        if (!in_array($type, $allowed, true)) {
            throw ApiException::validation('Bölüm görnüşi nädogry.', 'Недопустимый тип раздела.');
        }

        if ($type === Section::TYPE_QUIZ) {
            $exists = Capsule::table('sections')
                ->where('unit_section_id', $childUnitId)
                ->where('type', Section::TYPE_QUIZ)
                ->exists();

            if ($exists) {
                throw ApiException::validation(
                    'Bu bölümçede synag eýýäm bar — diňe bir synag bolup biler.',
                    'В этом юните уже есть экзамен-квиз — он может быть только один.'
                );
            }
        }

        $now = date('Y-m-d H:i:s');

        $id = Capsule::table('sections')->insertGetId([
            'unit_section_id' => $childUnitId,
            'type'            => $type,
            'title_tk'        => mb_substr(trim((string) ($body['title_tk'] ?? '')), 0, 200) ?: null,
            'title_ru'        => mb_substr(trim((string) ($body['title_ru'] ?? '')), 0, 200) ?: null,
            // FR-13.20: everything starts behind the publish gate.
            'status'          => Section::STATUS_DRAFT,
            'sort_order'      => (int) Capsule::table('sections')
                ->where('unit_section_id', $childUnitId)->max('sort_order') + 1,
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);

        return $this->json($response, ['id' => $id, 'type' => $type], 201);
    }

    /** Rename / reorder one typed section. */
    public function updateSection(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->requireSuperadmin($request);
        $section = $this->typedSection((int) $args['id']);
        $body = $this->body($request);

        $fields = ['updated_at' => date('Y-m-d H:i:s')];

        if (array_key_exists('title_tk', $body)) {
            $fields['title_tk'] = mb_substr(trim((string) $body['title_tk']), 0, 200) ?: null;
        }

        if (array_key_exists('title_ru', $body)) {
            $fields['title_ru'] = mb_substr(trim((string) $body['title_ru']), 0, 200) ?: null;
        }

        if (array_key_exists('sort_order', $body)) {
            $fields['sort_order'] = (int) $body['sort_order'];
        }

        Capsule::table('sections')->where('id', $section->id)->update($fields);

        // Quiz composition targets (FR-14.3). They live on the PARENT
        // unit_sections row, not on the section — updateSection silently
        // dropped them before, which made the panel's «Сохранить цели»
        // button a no-op. 0 in the panel means "no target": stored as
        // NULL so an all-zeros save keeps the legacy all-eligible mode
        // (hasTargets()) instead of creating an empty fixed draw.
        if ($section->type === Section::TYPE_QUIZ) {
            $targets = [];

            foreach (QuizDrawService::SKILLS as $skill) {
                $key = 'quiz_target_' . $skill;

                if (array_key_exists($key, $body)) {
                    $value = max(0, (int) $body[$key]);
                    $targets[$key] = $value === 0 ? null : $value;
                }
            }

            if ($targets !== []) {
                Capsule::table('unit_sections')
                    ->where('id', $section->unit_section_id)
                    ->update($targets);

                // Trim/backfill the stored draw to the new sizes now,
                // rather than surprising the next student who opens the
                // quiz. ensure() keeps still-valid picks (FR-14.3
                // self-heal), so unchanged skills keep their questions.
                $this->quizDraws->ensure((int) $section->unit_section_id);
            }
        }

        return $this->json($response, ['id' => (int) $section->id]);
    }

    /**
     * Deleting a section cascades into its sets, questions AND the
     * students' attempts on it. Content nobody attempted goes quietly;
     * attempted content needs the caller to say force=1, and the answer
     * states what was destroyed.
     */
    public function deleteSection(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->requireSuperadmin($request);
        $section = $this->typedSection((int) $args['id']);

        $attempts = Capsule::table('section_attempts')->where('section_id', $section->id)->count();
        $force = (string) ($request->getQueryParams()['force'] ?? '') === '1';

        if ($attempts > 0 && !$force) {
            throw new ApiException(
                'attempts_exist',
                "Bu bölümde okuwçylaryň {$attempts} synanyşygy bar. Pozulsa, olar hem pozular.",
                "У раздела есть {$attempts} попыток учеников. Удаление раздела удалит и их.",
                409
            );
        }

        Capsule::table('sections')->where('id', $section->id)->delete();

        return $this->json($response, [
            'ok'               => true,
            'attempts_deleted' => $attempts,
        ]);
    }

    /**
     * FR-13.20: the publish gate lives on the SECTION now. Students read
     * published sections only, filtered in ContentRepository.
     */
    public function setSectionStatus(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $scope = $this->scope($request);
        $scope->requireRole(User::ROLE_SUPERADMIN);

        $section = $this->typedSection((int) $args['id']);
        $status = (string) ($this->body($request)['status'] ?? '');

        if (!in_array($status, [Section::STATUS_DRAFT, Section::STATUS_PUBLISHED], true)) {
            throw ApiException::validation('Nädogry ýagdaý.', 'Недопустимый статус.');
        }

        Capsule::table('sections')->where('id', $section->id)->update([
            'status'     => $status,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        Capsule::table('audit_log')->insert([
            'actor_id'    => $scope->userId,
            'action'      => 'content.status_changed',
            'entity_type' => 'sections',
            'entity_id'   => (int) $section->id,
            'meta'        => json_encode(['status' => $status]),
            'created_at'  => date('Y-m-d H:i:s'),
        ]);

        return $this->json($response, ['id' => (int) $section->id, 'status' => $status]);
    }

    // ------------------------------------------------------- vocabulary

    public function saveVocabulary(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->requireSuperadmin($request);
        $body = $this->body($request);

        $term = trim((string) ($body['term_en'] ?? ''));
        $tk = trim((string) ($body['translation_tk'] ?? ''));
        $ru = trim((string) ($body['translation_ru'] ?? ''));

        if ($term === '' || $tk === '' || $ru === '') {
            throw ApiException::validation(
                'Söz we iki terjime hem gerek.',
                'Нужны слово и оба перевода.'
            );
        }

        $now = date('Y-m-d H:i:s');
        $fields = [
            'term_en'        => mb_substr($term, 0, 160),
            'part_of_speech' => mb_substr(trim((string) ($body['part_of_speech'] ?? '')), 0, 32) ?: null,
            'ipa'            => mb_substr(trim((string) ($body['ipa'] ?? '')), 0, 120) ?: null,
            'translation_tk' => mb_substr($tk, 0, 255),
            'translation_ru' => mb_substr($ru, 0, 255),
            'example_en'     => mb_substr(trim((string) ($body['example_en'] ?? '')), 0, 500) ?: null,
            'updated_at'     => $now,
        ];

        if (isset($args['id'])) {
            $updated = Capsule::table('vocabulary_items')->where('id', (int) $args['id'])->update($fields);

            if ($updated === 0) {
                throw ApiException::notFound();
            }

            return $this->json($response, ['id' => (int) $args['id']]);
        }

        $section = $this->typedSection((int) $args['section'], Section::TYPE_VOCABULARY);

        $fields['section_id'] = (int) $section->id;
        $fields['created_at'] = $now;
        $fields['sort_order'] = (int) Capsule::table('vocabulary_items')
            ->where('section_id', $section->id)->max('sort_order') + 1;

        return $this->json($response, ['id' => Capsule::table('vocabulary_items')->insertGetId($fields)], 201);
    }

    public function deleteVocabulary(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->requireSuperadmin($request);

        // Bookmarks cascade from here, which only loses a student's
        // shortcut to a word that no longer exists — acceptable.
        Capsule::table('vocabulary_items')->where('id', (int) $args['id'])->delete();

        return $this->json($response, ['ok' => true]);
    }

    // ---------------------------------------------------------- grammar

    /**
     * One explanation per grammar section. Its visibility is the
     * SECTION's status — grammar_explanations lost its own status column
     * in 008_redesign.sql.
     */
    public function saveGrammar(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->requireSuperadmin($request);
        $section = $this->typedSection((int) $args['section'], Section::TYPE_GRAMMAR);
        $body = $this->body($request);

        foreach (['title_tk', 'title_ru', 'body_tk', 'body_ru'] as $key) {
            if (trim((string) ($body[$key] ?? '')) === '') {
                throw ApiException::validation(
                    'Iki dilde-de at we tekst gerek.',
                    'Заголовок и текст нужны на обоих языках.'
                );
            }
        }

        $now = date('Y-m-d H:i:s');

        Capsule::table('grammar_explanations')->updateOrInsert(
            ['section_id' => (int) $section->id],
            [
                'title_tk'   => mb_substr((string) $body['title_tk'], 0, 200),
                'title_ru'   => mb_substr((string) $body['title_ru'], 0, 200),
                'body_tk'    => (string) $body['body_tk'],
                'body_ru'    => (string) $body['body_ru'],
                'examples'   => json_encode($body['examples'] ?? [], JSON_UNESCAPED_UNICODE),
                'updated_at' => $now,
                'created_at' => $now,
            ]
        );

        return $this->json($response, $this->grammarPayload((int) $section->id) ?? []);
    }

    public function deleteGrammar(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->requireSuperadmin($request);
        $section = $this->typedSection((int) $args['section'], Section::TYPE_GRAMMAR);

        Capsule::table('grammar_explanations')->where('section_id', (int) $section->id)->delete();

        return $this->json($response, ['ok' => true]);
    }

    // ----------------------------------------------------- exercise sets

    public function saveSet(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->requireSuperadmin($request);
        $body = $this->body($request);
        $now = date('Y-m-d H:i:s');

        $fields = [
            'title_tk'        => mb_substr(trim((string) ($body['title_tk'] ?? '')), 0, 200),
            'title_ru'        => mb_substr(trim((string) ($body['title_ru'] ?? '')), 0, 200),
            'instructions_tk' => mb_substr(trim((string) ($body['instructions_tk'] ?? '')), 0, 500) ?: null,
            'instructions_ru' => mb_substr(trim((string) ($body['instructions_ru'] ?? '')), 0, 500) ?: null,
            'updated_at'      => $now,
        ];

        if ($fields['title_tk'] === '' || $fields['title_ru'] === '') {
            throw ApiException::validation('Iki dilde-de at gerek.', 'Название нужно на обоих языках.');
        }

        if (isset($args['id'])) {
            $updated = Capsule::table('exercise_sets')->where('id', (int) $args['id'])->update($fields);

            if ($updated === 0) {
                throw ApiException::notFound();
            }

            return $this->json($response, ['id' => (int) $args['id']]);
        }

        $type = (string) ($body['type'] ?? '');
        $allowed = [
            ExerciseSet::TYPE_MULTIPLE_CHOICE,
            ExerciseSet::TYPE_FILL_BLANK,
            ExerciseSet::TYPE_REORDER,
            ExerciseSet::TYPE_MATCH_PAIRS,
            ExerciseSet::TYPE_FILL_LETTER_SPACE,
        ];

        if (!in_array($type, $allowed, true)) {
            throw ApiException::validation('Maşk görnüşi nädogry.', 'Недопустимый тип упражнения.');
        }

        $section = $this->typedSection((int) $args['section']);

        // FR-13.4: the quiz OWNS no questions — it serves its siblings'
        // eligible ones. A set attached to it would never be seen.
        if ($section->type === Section::TYPE_QUIZ) {
            throw ApiException::validation(
                'Synag bölümine maşk goşup bolmaýar — ol beýleki bölümleriň soraglaryndan düzülýär.',
                'В экзамен-квиз нельзя добавлять упражнения — он собирается из вопросов других разделов.'
            );
        }

        // Straight into a DB enum otherwise — garbage would 500 instead
        // of 422.
        $pairMode = (string) ($body['pair_mode'] ?? 'translation');
        $pairModes = ['translation', 'definition', 'sentence_halves', 'question_answer', 'synonym', 'antonym'];

        if ($type === ExerciseSet::TYPE_MATCH_PAIRS && !in_array($pairMode, $pairModes, true)) {
            throw ApiException::validation('Jübüt görnüşi nädogry.', 'Недопустимый режим пар.');
        }

        $fields['section_id'] = (int) $section->id;
        $fields['type'] = $type;
        $fields['status'] = 'draft';
        $fields['created_at'] = $now;
        // Type-specific columns are constrained by CHECKs, so they must
        // be null on every other type.
        $fields['pair_mode'] = $type === ExerciseSet::TYPE_MATCH_PAIRS ? $pairMode : null;
        $fields['input_mode'] = $type === ExerciseSet::TYPE_FILL_BLANK ? 'word_bank' : null;
        $fields['sort_order'] = (int) Capsule::table('exercise_sets')
            ->where('section_id', $section->id)->max('sort_order') + 1;

        return $this->json($response, ['id' => Capsule::table('exercise_sets')->insertGetId($fields)], 201);
    }

    /**
     * POST /manage/sets/{id}/quiz-eligible {eligible: bool} — flip the
     * quiz flag on EVERY active question of the set at once (FR-15.11).
     * One by one, 17 hand-authored questions meant 17 edit-save cycles
     * before the quiz pools saw anything; this is the panel's
     * «Все — в квиз» button. The child unit's stored draw self-heals
     * immediately, same as a target change.
     */
    public function setQuizEligibleBulk(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->requireSuperadmin($request);
        $setId = (int) $args['id'];
        $eligible = !empty($this->body($request)['eligible']) ? 1 : 0;

        $set = Capsule::table('exercise_sets as es')
            ->join('sections as s', 's.id', '=', 'es.section_id')
            ->where('es.id', $setId)
            ->first(['es.id', 's.unit_section_id']);

        if ($set === null) {
            throw ApiException::notFound();
        }

        $updated = Capsule::table('questions')
            ->where('exercise_set_id', $setId)
            ->where('is_active', 1)
            ->update(['quiz_eligible' => $eligible, 'updated_at' => date('Y-m-d H:i:s')]);

        $this->quizDraws->ensure((int) $set->unit_section_id);

        return $this->json($response, ['updated' => $updated, 'eligible' => (bool) $eligible]);
    }

    public function deleteSet(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->requireSuperadmin($request);
        $setId = (int) $args['id'];

        // §13: deleting a set revokes nothing a student earned — points
        // are gone (FR-13.7) and completed percentages live on
        // section_attempts, which does not cascade from here. Only the
        // per-question review rows (attempt_answers) go with their
        // questions, and losing review detail for deleted content is
        // acceptable (008_redesign.sql).
        Capsule::table('exercise_sets')->where('id', $setId)->delete();

        return $this->json($response, ['ok' => true]);
    }

    // -------------------------------------------------------- questions

    /**
     * FR-4.13: bilingual prompts plus the payload the students answer.
     * §13 additions: question_type (text/audio/picture, FR-13.13),
     * media_path for the audio/picture file, and the quiz_eligible flag
     * (FR-13.14).
     */
    public function saveQuestion(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->requireSuperadmin($request);
        $body = $this->body($request);
        $now = date('Y-m-d H:i:s');

        $payload = $body['payload'] ?? null;

        if (!is_array($payload)) {
            throw ApiException::validation('Sorag maglumaty nädogry.', 'Некорректные данные вопроса.');
        }

        $questionType = (string) ($body['question_type'] ?? 'text');

        if (!in_array($questionType, ['text', 'audio', 'picture'], true)) {
            throw ApiException::validation('Soragyň görnüşi nädogry.', 'Недопустимый тип вопроса.');
        }

        $setId = isset($args['id'])
            ? (int) Capsule::table('questions')->where('id', (int) $args['id'])->value('exercise_set_id')
            : (int) $args['set'];

        $type = (string) Capsule::table('exercise_sets')->where('id', $setId)->value('type');

        if ($type === '') {
            throw ApiException::notFound();
        }

        // Shared with the xlsx import — a payload reaches storage only
        // through this gate, whichever door it came in by.
        QuestionPayload::assert($type, $payload);
        $payload = QuestionPayload::normalise($type, $payload);

        $fields = [
            'question_type'   => $questionType,
            'media_path'      => mb_substr(trim((string) ($body['media_path'] ?? '')), 0, 255) ?: null,
            'quiz_eligible'   => !empty($body['quiz_eligible']) ? 1 : 0,
            'prompt_tk'       => mb_substr(trim((string) ($body['prompt_tk'] ?? '')), 0, 500) ?: null,
            'prompt_ru'       => mb_substr(trim((string) ($body['prompt_ru'] ?? '')), 0, 500) ?: null,
            'payload'         => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'is_human_edited' => 1,
            'updated_at'      => $now,
        ];

        if (isset($args['id'])) {
            Capsule::table('questions')->where('id', (int) $args['id'])->update($fields);

            return $this->json($response, ['id' => (int) $args['id']]);
        }

        $fields['exercise_set_id'] = $setId;
        $fields['is_active'] = 1;
        $fields['created_at'] = $now;
        $fields['sort_order'] = (int) Capsule::table('questions')
            ->where('exercise_set_id', $setId)->max('sort_order') + 1;

        return $this->json($response, ['id' => Capsule::table('questions')->insertGetId($fields)], 201);
    }

    public function deleteQuestion(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->requireSuperadmin($request);

        // Soft delete. A hard delete cascades into attempt_answers and
        // would destroy the per-question review material of every
        // attempt that included this question (FR-12.10).
        $updated = Capsule::table('questions')
            ->where('id', (int) $args['id'])
            ->update(['is_active' => 0, 'updated_at' => date('Y-m-d H:i:s')]);

        if ($updated === 0) {
            throw ApiException::notFound();
        }

        return $this->json($response, ['ok' => true]);
    }

    // ---------------------------------------------------------- helpers

    /**
     * The quiz's composition, for the editor's read-only preview:
     * every quiz_eligible question of the child unit's non-quiz
     * sections, in source order (FR-13.4). Draft sections are included
     * but flagged — students only ever get the published ones, and the
     * superadmin needs to see the difference.
     *
     * @return list<array<string, mixed>>
     */
    /**
     * Servable-eligible pool sizes per skill — the «в наличии: N» hints
     * beside the target inputs. Delegates to the SAME pool the draw uses
     * (published section + published set + active + eligible + media
     * uploaded), so the hint can never promise questions the draw would
     * then refuse.
     *
     * @return array{vocabulary: int, grammar: int, listening: int}
     */
    private function quizPools(int $childUnitId): array
    {
        $out = [];

        foreach (QuizDrawService::SKILLS as $skill) {
            $out[$skill] = count($this->quizDraws->pool($childUnitId, $skill));
        }

        return $out;
    }

    /**
     * The stored fixed draw (FR-14.3) as display codes for the
     * «Текущий набор» badges — external codes where the xlsx import set
     * them, '#id' for hand-authored questions.
     *
     * @return list<string>
     */
    private function quizDrawCodes(int $childUnitId): array
    {
        return Capsule::table('quiz_draws as qd')
            ->join('questions as q', 'q.id', '=', 'qd.question_id')
            ->where('qd.unit_section_id', $childUnitId)
            ->orderBy('qd.sort_order')->orderBy('qd.question_id')
            ->get(['q.id', 'q.external_code'])
            ->map(static fn ($r): string => (string) ($r->external_code ?? '') !== ''
                ? (string) $r->external_code
                : ('#' . $r->id))
            ->all();
    }

    private function quizPreview(int $childUnitId): array
    {
        return Capsule::table('questions as q')
            ->join('exercise_sets as es', 'es.id', '=', 'q.exercise_set_id')
            ->join('sections as sec', 'sec.id', '=', 'es.section_id')
            ->where('sec.unit_section_id', $childUnitId)
            ->where('sec.type', '!=', Section::TYPE_QUIZ)
            ->where('q.is_active', 1)
            ->where('q.quiz_eligible', 1)
            ->orderBy('sec.sort_order')
            ->orderBy('sec.id')
            ->orderBy('es.sort_order')
            ->orderBy('es.id')
            ->orderBy('q.sort_order')
            ->orderBy('q.id')
            ->select([
                'q.id', 'q.question_type', 'q.payload',
                'es.type as set_type',
                'sec.id as section_id', 'sec.type as section_type',
                'sec.title_ru as section_title_ru', 'sec.status as section_status',
            ])
            ->get()
            ->map(fn ($row): array => [
                'id'               => (int) $row->id,
                'question_type'    => $row->question_type,
                'set_type'         => $row->set_type,
                'payload'          => json_decode((string) $row->payload, true),
                'section_id'       => (int) $row->section_id,
                'section_type'     => $row->section_type,
                'section_title_ru' => $row->section_title_ru,
                'section_status'   => $row->section_status,
            ])
            ->all();
    }

    private function grammarPayload(int $sectionId): ?array
    {
        $row = Capsule::table('grammar_explanations')->where('section_id', $sectionId)->first();

        if ($row === null) {
            return null;
        }

        return [
            'title_tk' => $row->title_tk,
            'title_ru' => $row->title_ru,
            'body_tk'  => $row->body_tk,
            'body_ru'  => $row->body_ru,
            'examples' => json_decode((string) $row->examples, true) ?? [],
        ];
    }

    /**
     * The typed section, optionally required to be of one type — a
     * vocabulary write aimed at a grammar section is a caller bug and
     * answers 422, not silent misfiling.
     */
    private function typedSection(int $id, ?string $requiredType = null): object
    {
        $section = Capsule::table('sections')->where('id', $id)->first();

        if ($section === null) {
            throw ApiException::notFound();
        }

        if ($requiredType !== null && $section->type !== $requiredType) {
            throw ApiException::validation(
                'Bölümiň görnüşi gabat gelmeýär.',
                'Тип раздела не подходит для этого действия.'
            );
        }

        return $section;
    }

    private function requireSuperadmin(ServerRequestInterface $request): void
    {
        $this->scope($request)->requireRole(User::ROLE_SUPERADMIN);
    }
}
