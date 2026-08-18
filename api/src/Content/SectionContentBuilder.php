<?php

declare(strict_types=1);

namespace Dana\Content;

use Dana\Content\Ingestion\PageImageExtractor;
use Dana\Content\Llm\GeminiProvider;
use Dana\Content\Llm\LlmException;
use Dana\Domain\Models\ExerciseSet;
use Dana\Domain\Models\UnitSection;
use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * The generation work itself, callable from either the CLI scripts or the
 * queue worker.
 *
 * Everything here is idempotent and additive: OCR skips pages that
 * already have text, vocabulary and grammar are only proposed when absent,
 * and generating an exercise set always creates a NEW set rather than
 * replacing one — a second batch of multiple choice is more practice, not
 * a conflict (FR-8.13).
 */
final class SectionContentBuilder
{
    private const OCR_PROMPT = <<<'PROMPT'
Transcribe this textbook page exactly as printed.

Rules:
  - Output plain text only. No commentary, no markdown fences.
  - Preserve the reading order: headings, then each exercise in order.
  - Keep exercise numbers and letters (1, 2, a, b, ...) as printed.
  - Transcribe every full sentence you can read, verbatim.
  - Mark a section you cannot read as [unreadable].
  - If the page is a picture with no text, output exactly: [no text]
PROMPT;

    /** @var callable(string):void */
    private $log;

    public function __construct(
        private readonly GeminiProvider $provider,
        private readonly SectionGenerator $generator,
        ?callable $log = null,
    ) {
        $this->log = $log ?? static function (string $line): void {};
    }

    private function say(string $line): void
    {
        ($this->log)($line);
    }

    /** Transcribes any mapped page that has no usable text yet. */
    public function ensureOcr(UnitSection $section): int
    {
        $sources = Capsule::table('section_sources')
            ->where('unit_section_id', $section->id)
            ->whereNotNull('confirmed_by')
            ->get();

        $done = 0;

        foreach ($sources as $source) {
            $book = Capsule::table('books')->find($source->book_id);

            if ($book === null) {
                continue;
            }

            $images = (new PageImageExtractor())->extract(
                $book->file_path,
                dirname(__DIR__, 3) . '/storage/pages/book-' . $book->id
            );

            for ($page = $source->page_from; $page <= $source->page_to; $page++) {
                $existing = Capsule::table('book_pages')
                    ->where('book_id', $book->id)->where('page_number', $page)->first();

                if ($existing !== null && mb_strlen((string) $existing->raw_text) > 20) {
                    continue;
                }

                $image = $images[$page - 1] ?? null;

                if ($image === null) {
                    $this->say("page {$page}: no image");
                    continue;
                }

                // A rate limit propagates so the worker can requeue
                // rather than record a false failure.
                $result = $this->provider->transcribeImage($image, self::OCR_PROMPT);

                Capsule::table('book_pages')->updateOrInsert(
                    ['book_id' => $book->id, 'page_number' => $page],
                    ['raw_text' => $result->text]
                );

                $this->say("page {$page}: " . mb_strlen($result->text) . ' chars');
                $done++;
                usleep(800_000);
            }
        }

        return $done;
    }

    /**
     * FR-6.2 makes vocabulary superadmin-authored; what this writes is a
     * proposal drawn from the same pages, meant to be reviewed.
     */
    public function proposeVocabulary(UnitSection $section, array $pool): int
    {
        if (Capsule::table('vocabulary_items')->where('unit_section_id', $section->id)->exists()) {
            $this->say('vocabulary already present, skipped');
            return 0;
        }

        $schema = [
            'type'       => 'OBJECT',
            'properties' => ['items' => [
                'type'  => 'ARRAY',
                'items' => [
                    'type'       => 'OBJECT',
                    'properties' => [
                        'term_en'        => ['type' => 'STRING'],
                        'part_of_speech' => ['type' => 'STRING'],
                        'ipa'            => ['type' => 'STRING'],
                        'translation_tk' => ['type' => 'STRING'],
                        'translation_ru' => ['type' => 'STRING'],
                        'example_en'     => ['type' => 'STRING'],
                    ],
                    'required' => ['term_en', 'translation_tk', 'translation_ru'],
                ],
            ]],
            'required' => ['items'],
        ];

        $result = $this->provider->complete(
            "You extract the key vocabulary a beginner must learn from a textbook section.\n"
            . "Give the Turkmen and Russian translation of each word.\n"
            . 'Only words that actually appear in the text. 10-16 items. Strict JSON.',
            "SECTION: {$section->label()} — {$section->title}\n\nTEXT\n"
            . implode("\n", array_column($pool, 'text')),
            $schema,
            0.1
        );

        $now = date('Y-m-d H:i:s');
        $rows = [];

        foreach ($result->json['items'] ?? [] as $i => $item) {
            $rows[] = [
                'unit_section_id' => $section->id,
                'term_en'         => mb_substr((string) $item['term_en'], 0, 160),
                'part_of_speech'  => mb_substr((string) ($item['part_of_speech'] ?? ''), 0, 32) ?: null,
                'ipa'             => mb_substr((string) ($item['ipa'] ?? ''), 0, 120) ?: null,
                'translation_tk'  => mb_substr((string) $item['translation_tk'], 0, 255),
                'translation_ru'  => mb_substr((string) $item['translation_ru'], 0, 255),
                'example_en'      => mb_substr((string) ($item['example_en'] ?? ''), 0, 500) ?: null,
                'sort_order'      => $i,
                'created_at'      => $now,
                'updated_at'      => $now,
            ];
        }

        if ($rows !== []) {
            Capsule::table('vocabulary_items')->insert($rows);
        }

        $this->say(count($rows) . ' vocabulary items proposed');

        return count($rows);
    }

    /** FR-5: simplified explanation in both languages, extra examples. */
    public function generateGrammar(UnitSection $section, array $pool): bool
    {
        if (Capsule::table('grammar_explanations')->where('unit_section_id', $section->id)->exists()) {
            $this->say('grammar already present, skipped');
            return false;
        }

        $schema = [
            'type'       => 'OBJECT',
            'properties' => [
                'title_tk' => ['type' => 'STRING'],
                'title_ru' => ['type' => 'STRING'],
                'body_tk'  => ['type' => 'STRING'],
                'body_ru'  => ['type' => 'STRING'],
                'examples' => [
                    'type'  => 'ARRAY',
                    'items' => [
                        'type'       => 'OBJECT',
                        'properties' => [
                            'en'      => ['type' => 'STRING'],
                            'note_tk' => ['type' => 'STRING'],
                            'note_ru' => ['type' => 'STRING'],
                        ],
                        'required' => ['en', 'note_tk', 'note_ru'],
                    ],
                ],
            ],
            'required' => ['title_tk', 'title_ru', 'body_tk', 'body_ru', 'examples'],
        ];

        $result = $this->provider->complete(
            "You explain English grammar to beginners in TURKMEN and RUSSIAN.\n"
            . "Simplify: short sentences, no grammar jargon the learner has not met.\n"
            . "Give at least 6 examples — more than the book does.\n"
            . "English examples stay in English; the notes are translated.\n"
            . 'The Turkmen and Russian versions must say the same thing. Strict JSON.',
            "SECTION: {$section->label()} — {$section->title}\n\nSOURCE TEXT\n"
            . implode("\n", array_column($pool, 'text')),
            $schema,
            0.2
        );

        $g = $result->json;
        $now = date('Y-m-d H:i:s');

        Capsule::table('grammar_explanations')->insert([
            'unit_section_id' => $section->id,
            'title_tk'        => mb_substr((string) $g['title_tk'], 0, 200),
            'title_ru'        => mb_substr((string) $g['title_ru'], 0, 200),
            'body_tk'         => (string) $g['body_tk'],
            'body_ru'         => (string) $g['body_ru'],
            'examples'        => json_encode($g['examples'] ?? [], JSON_UNESCAPED_UNICODE),
            // FR-4.17: everything generated starts as a draft.
            'status'          => 'draft',
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);

        $this->say('grammar written with ' . count($g['examples'] ?? []) . ' examples');

        return true;
    }

    /**
     * Always creates a NEW exercise set, so this doubles as "give me more
     * practice of this type" (FR-8.13).
     *
     * @return array{created: bool, kept: int, dropped: int, reason: string|null}
     */
    public function generateExerciseSet(UnitSection $section, string $type, array $pool): array
    {
        $vocabulary = Capsule::table('vocabulary_items')
            ->join('unit_sections', 'unit_sections.id', '=', 'vocabulary_items.unit_section_id')
            // FR-4.7: cumulative — this section and everything before it.
            ->where('unit_sections.level_position', '<=', $section->level_position)
            ->pluck('vocabulary_items.term_en')
            ->all();

        $outcome = $this->generator->generateSet($section, $type, $pool, $vocabulary);
        $kept = count($outcome['kept']);
        $dropped = count($outcome['dropped']);

        if (!$outcome['viable']) {
            // FR-4.20: never pad a type the pages cannot support.
            $reason = "only {$kept} valid items, needs {$outcome['min']} ({$dropped} rejected by gates)";
            $this->say("{$type}: SKIPPED — {$reason}");

            return ['created' => false, 'kept' => $kept, 'dropped' => $dropped, 'reason' => $reason];
        }

        $now = date('Y-m-d H:i:s');
        $existing = Capsule::table('exercise_sets')
            ->where('unit_section_id', $section->id)->where('type', $type)->count();
        $suffix = $existing > 0 ? ' ' . ($existing + 1) : '';

        [$titleTk, $titleRu, $instrTk, $instrRu] = self::labels($type);

        $setId = Capsule::table('exercise_sets')->insertGetId([
            'unit_section_id' => $section->id,
            'type'            => $type,
            'title_tk'        => $titleTk . $suffix,
            'title_ru'        => $titleRu . $suffix,
            'instructions_tk' => $instrTk,
            'instructions_ru' => $instrRu,
            'input_mode'      => $type === ExerciseSet::TYPE_FILL_BLANK ? 'word_bank' : null,
            'pair_mode'       => $type === ExerciseSet::TYPE_MATCH_PAIRS ? 'translation' : null,
            'status'          => 'draft',
            'sort_order'      => Capsule::table('exercise_sets')->where('unit_section_id', $section->id)->count(),
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);

        $sort = 0;
        $pairs = [];

        foreach (array_slice($outcome['kept'], 0, $outcome['max']) as $entry) {
            $item = $entry['item'];
            $source = $entry['source'];

            if ($type === ExerciseSet::TYPE_MATCH_PAIRS) {
                // Match pairs come back one pair per item; the screen
                // shows them as a single question.
                $pairs[] = ['left' => $item['left'], 'right' => $item['right']];
                continue;
            }

            Capsule::table('questions')->insert([
                'exercise_set_id' => $setId,
                'sort_order'      => $sort++,
                'prompt_tk'       => $instrTk,
                'prompt_ru'       => $instrRu,
                'payload'         => json_encode(self::payload($type, $item), JSON_UNESCAPED_UNICODE),
                'source_book_id'  => $source['book'] ?? null,
                'source_page'     => $source['page'] ?? null,
                'source_sentence' => $source['text'] ?? null,
                'change_ratio'    => $entry['ratio'],
                'is_active'       => 1,
                'created_at'      => $now,
                'updated_at'      => $now,
            ]);
        }

        if ($pairs !== []) {
            Capsule::table('questions')->insert([
                'exercise_set_id' => $setId,
                'sort_order'      => 0,
                'prompt_tk'       => $instrTk,
                'prompt_ru'       => $instrRu,
                'payload'         => json_encode(
                    ['pairs' => array_slice($pairs, 0, ExerciseSet::MAX_PAIRS)],
                    JSON_UNESCAPED_UNICODE
                ),
                'is_active'       => 1,
                'created_at'      => $now,
                'updated_at'      => $now,
            ]);
            $sort = 1;
        }

        $this->say("{$type}: {$sort} questions kept, {$dropped} rejected by gates");

        return ['created' => true, 'kept' => $sort, 'dropped' => $dropped, 'reason' => null];
    }

    /** @return list<array{text: string, page: int, book: int}> */
    public function pool(UnitSection $section): array
    {
        return $this->generator->buildPool($section);
    }

    private static function payload(string $type, array $item): array
    {
        return match ($type) {
            ExerciseSet::TYPE_MULTIPLE_CHOICE => [
                'stem'    => $item['stem'],
                'options' => $item['options'],
                'answer'  => (int) $item['answer_index'],
            ],
            ExerciseSet::TYPE_FILL_BLANK => [
                'before'    => $item['before'],
                'after'     => $item['after'],
                'answer'    => [$item['answer']],
                'word_bank' => $item['word_bank'],
            ],
            ExerciseSet::TYPE_REORDER => [
                'tokens' => $item['tokens'],
                'answer' => range(0, count($item['tokens']) - 1),
            ],
            default => throw new LlmException("Unsupported type {$type}"),
        };
    }

    /** @return array{0: string, 1: string, 2: string, 3: string} */
    private static function labels(string $type): array
    {
        return match ($type) {
            ExerciseSet::TYPE_MULTIPLE_CHOICE => ['Test', 'Тест', 'Dogry jogaby saýlaň', 'Выберите правильный ответ'],
            ExerciseSet::TYPE_FILL_BLANK      => ['Boşluklary doldur', 'Заполни пропуски', 'Boşluklary dolduryň', 'Заполните пропуски'],
            ExerciseSet::TYPE_REORDER         => ['Sözleri tertiple', 'Порядок слов', 'Sözleri tertipleşdiriň', 'Расставьте слова по порядку'],
            ExerciseSet::TYPE_MATCH_PAIRS     => ['Jübütleri tap', 'Найди пары', 'Jübütleri birleşdiriň', 'Соедините пары'],
            default                           => ['Maşk', 'Упражнение', '', ''],
        };
    }
}
