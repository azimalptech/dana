<?php

declare(strict_types=1);

namespace Dana\Content;

use Dana\Content\Llm\LlmProvider;
use Dana\Content\Validation\ChangeRatio;
use Dana\Domain\Models\ExerciseSet;
use Dana\Domain\Models\UnitSection;
use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * Stages 4-7 of docs/05-CONTENT-PIPELINE.md for one unit section.
 *
 * The model only ever sees sentences taken from the pages mapped to this
 * section (FR-4.2), and every question it returns must name the source
 * sentence it came from (FR-4.3) and land inside the change band
 * (FR-4.4) or it is dropped. A type that cannot produce enough valid
 * questions is reported, never padded (FR-4.20).
 */
final class SectionGenerator
{
    /** How many exercise items one source sentence may produce (gate G9). */
    private const MAX_ITEMS_PER_SOURCE = 2;

    /** Markers for material excluded by FR-4.9. */
    private const EXCLUDE_PATTERNS = [
        '/\b(listen|listening|audioscript)\b/i',
        '/\b(in pairs|talk about|ask your partner|ask and answer|practise saying)\b/i',
        '/\b\d+\.\d+\b/',                 // audio track references, e.g. 1.14
        '/\b(pronunciation|repeat the words)\b/i',
    ];

    /**
     * Textbook apparatus — headings, rubrics, cross-references and drills.
     *
     * These are printed as sentences but are not language to practise.
     * Left in, they crowd out the handful of real sentences on a page and
     * the generator ends up rewriting the same one repeatedly because it
     * is the only usable item in the pool.
     */
    private const APPARATUS_PATTERNS = [
        '/\b(grammar|vocabulary|pronunciation)\s+bank\b/i',
        '/\bp\.\s?\d+/i',                                  // "p.92"
        '~[/\[][a-zɪəʊæɑːʌɒeɜʃʒθðŋj]+[/\]]~iu',            // phonetic symbols
        '/^\s*\d+\s+(grammar|vocabulary|pronunciation|reading|writing|speaking)\b/i',
        '/^\s*[a-z]\s+(write|complete|match|look|read|circle|underline|tick|cover|check|do)\b/i',
        '/^\s*(write|complete|match|look at|read|circle|underline|tick|cover|choose)\b/i',
        '/\b(part \d|exercise \d)\b/i',
        '/^\s*\d+[A-Z]\b/',                                // "1A A cappuccino"
        '/\bcommunication\b/i',
    ];

    /**
     * Words whose members can substitute for one another in most frames.
     *
     * If the correct answer and a distractor come from the same set, the
     * item usually has several right answers — «See ___ on Friday» with
     * us/me/them/him marks three correct sentences wrong. Gate G11
     * rejects those, with one carve-out: subject pronouns and the verb
     * "be" agree, so «Where are ___ from?» legitimately keeps she/he/it
     * as wrong options against "they".
     */
    private const INTERCHANGEABLE_SETS = [
        'subject_pronouns' => ['i', 'you', 'he', 'she', 'it', 'we', 'they'],
        'object_pronouns'  => ['me', 'you', 'him', 'her', 'it', 'us', 'them'],
        'possessives'      => ['my', 'your', 'his', 'her', 'its', 'our', 'their'],
        'demonstratives'   => ['this', 'that', 'these', 'those'],
        'locatives'        => ['here', 'there'],
        'days'             => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'],
        'articles'         => ['a', 'an', 'the'],
    ];

    /** be-form → the subject pronouns it agrees with. */
    private const BE_AGREEMENT = [
        'am'  => ['i'],
        'is'  => ['he', 'she', 'it'],
        'are' => ['you', 'we', 'they'],
    ];

    /**
     * Grammar-book metalanguage. It belongs in explanations, never inside
     * an exercise item — "singular" turned up as a distractor once.
     */
    private const METALANGUAGE = [
        'singular', 'plural', 'noun', 'verb', 'adjective', 'pronoun',
        'article', 'preposition', 'sentence', 'question', 'answer',
        'grammar', 'vocabulary', 'exercise', 'positive', 'negative',
    ];

    public function __construct(
        private readonly LlmProvider $provider,
        private readonly ?LlmProvider $judge = null,
    ) {
    }

    /**
     * Sentences the generator is allowed to rewrite.
     *
     * @return list<array{text: string, page: int}>
     */
    public function buildPool(UnitSection $section): array
    {
        $sources = Capsule::table('section_sources')
            ->where('unit_section_id', $section->id)
            ->whereNotNull('confirmed_by')   // FR-4.15
            ->get();

        $pool = [];
        $seen = [];

        foreach ($sources as $source) {
            $pages = Capsule::table('book_pages')
                ->where('book_id', $source->book_id)
                ->whereBetween('page_number', [$source->page_from, $source->page_to])
                ->orderBy('page_number')
                ->get();

            foreach ($pages as $page) {
                foreach ($this->sentencesFrom((string) $page->raw_text) as $sentence) {
                    // Deduplicate across the WHOLE pool, not just within a
                    // page. A sentence printed on two pages would otherwise
                    // occupy two indices, and the one-item-per-source gate
                    // (G9) would wave both through — producing two near
                    // identical questions from the same sentence.
                    $key = mb_strtolower(preg_replace('/\s+/u', ' ', trim($sentence)) ?? '');

                    if ($key === '' || isset($seen[$key])) {
                        continue;
                    }

                    $seen[$key] = true;
                    $pool[] = [
                        'text' => $sentence,
                        'page' => (int) $page->page_number,
                        'book' => (int) $source->book_id,
                    ];
                }
            }
        }

        return $pool;
    }

    /** @return list<string> */
    private function sentencesFrom(string $text): array
    {
        $out = [];

        // Dialogues are often printed with bare "A" / "B" speaker marks
        // rather than names. "A" is also a real English word, so it is
        // only treated as a speaker when the same page also uses "B"
        // that way — which is what makes it a dialogue.
        $isDialogue = preg_match('/(^|\R)\s*B\s+\p{Lu}/u', $text) === 1;

        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || $line === '[no text]') {
                continue;
            }

            $line = $this->stripLabels($line, $isDialogue);

            foreach (preg_split('/(?<=[.!?])\s+/u', $line) ?: [] as $candidate) {
                $candidate = trim($candidate);
                $words = count(ChangeRatio::tokenize($candidate));

                // Too short to rewrite meaningfully, too long for a
                // Beginner exercise item.
                if ($words < 4 || $words > 20) {
                    continue;
                }

                foreach ([...self::EXCLUDE_PATTERNS, ...self::APPARATUS_PATTERNS] as $pattern) {
                    if (preg_match($pattern, $candidate) === 1) {
                        continue 2;
                    }
                }

                // A real sentence ends in punctuation. Fragments like
                // "1 I am (I'm" are leftovers of a table or gap-fill and
                // cannot be rewritten into anything sensible.
                if (preg_match('/[.!?]["\')]?$/u', $candidate) !== 1) {
                    continue;
                }

                // Mostly-digits lines are number drills, not language.
                $letters = preg_match_all('/\p{L}/u', $candidate);
                $digits = preg_match_all('/\d/u', $candidate);

                if ($digits > 0 && $letters < $digits * 2) {
                    continue;
                }

                $out[] = $candidate;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Removes the printing furniture that sits in front of a sentence.
     *
     * Textbooks prefix lines with exercise numbering ("4 a He's nice.")
     * and speaker marks ("Helen:", or a bare "A"/"B" in a dialogue).
     * None of it is the language being taught, and left in it lands
     * inside exercises as "4 a ___ good." — unanswerable.
     */
    private function stripLabels(string $line, bool $isDialogue): string
    {
        // "4 a ", "12 b " — exercise numbering.
        $line = (string) preg_replace('/^\s*\d{1,2}\s+[a-z]\s+/u', '', $line);

        // A bare lowercase list marker: "b Where's she from?". Only
        // stripped before a capital — "a cappuccino, please." keeps its
        // article because the next word is lowercase.
        $line = (string) preg_replace('/^\s*[a-h]\s+(?=\p{Lu})/u', '', $line);

        // "Helen:", "Barista 2:" — named speaker.
        $line = (string) preg_replace('/^\s*\p{Lu}[\p{L}\']{0,14}(\s\d{1,2})?\s*:\s*/u', '', $line);

        // A bare capital used as a speaker mark. B-Z are never English
        // words on their own; "A" and "I" are, so "A" is only stripped
        // inside something already identified as a dialogue.
        $line = (string) preg_replace('/^\s*[B-HJ-Z]\s+(?=\p{Lu})/u', '', $line);

        if ($isDialogue) {
            $line = (string) preg_replace('/^\s*A\s+(?=\p{Lu})/u', '', $line);
        }

        return trim($line);
    }

    /**
     * Generates one exercise set and returns it with per-question gate
     * results attached, so a caller can report exactly why anything was
     * dropped rather than just how many survived.
     *
     * @param list<array{text: string, page: int, book: int}> $pool
     */
    public function generateSet(UnitSection $section, string $type, array $pool, array $allowedVocabulary): array
    {
        [$min, $max] = (new ExerciseSet(['type' => $type]))->questionBounds();

        $numbered = '';
        foreach ($pool as $i => $item) {
            $numbered .= "{$i}. {$item['text']}\n";
        }

        $system = $this->systemPrompt($type);
        $user = "SOURCE SENTENCES\n{$numbered}\n"
            . "ALLOWED VOCABULARY (plus function words)\n" . implode(', ', $allowedVocabulary) . "\n\n"
            . "SECTION: {$section->label()} — {$section->title}\n\n"
            . "TASK\nProduce {$max} items of type {$type}.";

        $result = $this->provider->complete($system, $user, $this->schemaFor($type), 0.3);

        $items = $result->json['items'] ?? [];
        $kept = [];
        $dropped = [];
        $usedSources = [];

        foreach ($items as $item) {
            $index = $item['source_index'] ?? null;
            $source = is_int($index) ? ($pool[$index] ?? null) : null;

            // G1 — provenance. No source, no question.
            if ($source === null && $type !== ExerciseSet::TYPE_MATCH_PAIRS) {
                $dropped[] = ['reason' => 'G1_no_source', 'item' => $item];
                continue;
            }

            // G9 — cap how many items may come from one source sentence.
            //
            // Unbounded, the model fixates on a single easy sentence and
            // returns a dozen near-identical variants, which drills
            // nothing. But one-per-sentence is too strict for real pages:
            // a Beginner spread yields only about five complete
            // sentences once headings, rubrics and drills are stripped,
            // which would put every type below the 7-question floor.
            //
            // Two per sentence is the compromise — enough for a full set
            // from a sparse page, few enough that no sentence dominates.
            // The right long-term fix is mapping the Workbook pages in as
            // well, which is exactly what a workbook is for.
            if ($source !== null) {
                $usedSources[$index] = ($usedSources[$index] ?? 0) + 1;

                if ($usedSources[$index] > self::MAX_ITEMS_PER_SOURCE) {
                    $dropped[] = ['reason' => 'G9_source_overused', 'index' => $index, 'item' => $item];
                    continue;
                }
            }

            // G6 — structural shape. The response schema constrains field
            // names, not their content: the model can still return four
            // options where two are identical, an answer index out of
            // bounds, or a "correct" word missing from its own word bank.
            // Any of those is unanswerable or ambiguous for the student.
            if (!$this->hasValidShape($type, $item)) {
                $dropped[] = ['reason' => 'G6_shape', 'item' => $item];
                continue;
            }

            $ratio = null;

            if ($type !== ExerciseSet::TYPE_MATCH_PAIRS) {
                $rendered = $this->renderForComparison($type, $item);
                $ratio = ChangeRatio::measure($source['text'], $rendered);

                // G2 — the 20-25% rule.
                if (!ChangeRatio::isAccepted($ratio)) {
                    $dropped[] = ['reason' => 'G2_change_ratio', 'ratio' => $ratio, 'item' => $item];
                    continue;
                }
            }

            // G10 — the book's own word must never be a wrong option.
            //
            // The failure this closes: «Practise with other ___» marked
            // "names" correct while "students" — the actual sentence —
            // sat in the bank as a distractor. If putting any distractor
            // into the gap reproduces the source sentence, the item is
            // teaching the student that the book is wrong.
            if ($source !== null && $this->distractorReproducesSource($type, $item, $source['text'])) {
                $dropped[] = ['reason' => 'G10_source_as_distractor', 'item' => $item];
                continue;
            }

            // G11 — interchangeable options.
            if ($this->hasInterchangeableOptions($type, $item)) {
                $dropped[] = ['reason' => 'G11_ambiguous_options', 'item' => $item];
                continue;
            }

            // Metalanguage — grammar-book terms are not exercise words.
            if ($this->usesMetalanguage($type, $item)) {
                $dropped[] = ['reason' => 'G11_metalanguage', 'item' => $item];
                continue;
            }

            $kept[] = [
                'item'   => $item,
                'source' => $source,
                'ratio'  => $ratio,
            ];
        }

        // G8 — the judge pass. Deterministic gates cannot see meaning:
        // "Practise with other names" passes every structural check and
        // is still nonsense. A second model, temperature 0, checks each
        // survivor for naturalness and for a genuinely single answer.
        if ($this->judge !== null && $kept !== [] && $type !== ExerciseSet::TYPE_MATCH_PAIRS) {
            [$kept, $judgeDropped] = $this->judgePass($type, $kept);
            $dropped = [...$dropped, ...$judgeDropped];
        }

        return [
            'type'          => $type,
            'kept'          => $kept,
            'dropped'       => $dropped,
            'min'           => $min,
            'max'           => $max,
            // FR-4.20 — below the floor the whole type is skipped.
            'viable'        => count($kept) >= $min,
            'input_tokens'  => $result->inputTokens,
            'output_tokens' => $result->outputTokens,
        ];
    }

    /* ------------------------------------------------------ gates 10/11 */

    /** @return list<string> options of the item other than the answer */
    private function distractorsOf(string $type, array $item): array
    {
        if ($type === ExerciseSet::TYPE_MULTIPLE_CHOICE) {
            $options = $item['options'] ?? [];
            unset($options[$item['answer_index'] ?? -1]);

            return array_values(array_map(strval(...), $options));
        }

        if ($type === ExerciseSet::TYPE_FILL_BLANK) {
            $answer = mb_strtolower(trim((string) ($item['answer'] ?? '')));

            return array_values(array_filter(
                array_map(strval(...), $item['word_bank'] ?? []),
                static fn (string $w): bool => mb_strtolower(trim($w)) !== $answer
            ));
        }

        return [];
    }

    /** The sentence the student sees with X in the gap. */
    private function frameWith(string $type, array $item, string $word): string
    {
        if ($type === ExerciseSet::TYPE_MULTIPLE_CHOICE) {
            return str_replace('___', $word, (string) ($item['stem'] ?? ''));
        }

        return trim(($item['before'] ?? '') . $word . ($item['after'] ?? ''));
    }

    private static function normaliseSentence(string $text): string
    {
        $text = mb_strtolower($text);
        $text = (string) preg_replace('/[^\p{L}\p{N}\s]/u', '', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private function distractorReproducesSource(string $type, array $item, string $source): bool
    {
        if (!in_array($type, [ExerciseSet::TYPE_MULTIPLE_CHOICE, ExerciseSet::TYPE_FILL_BLANK], true)) {
            return false;
        }

        $normalSource = self::normaliseSentence($source);

        foreach ($this->distractorsOf($type, $item) as $distractor) {
            if (self::normaliseSentence($this->frameWith($type, $item, $distractor)) === $normalSource) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when the answer and a distractor could both fill the gap.
     *
     * Members of one closed class are interchangeable in most frames, so
     * such an item is rejected — except subject pronouns, where a
     * neighbouring form of "be" pins the group down: with "are" in the
     * frame, "they" is right and "she" is honestly wrong.
     */
    private function hasInterchangeableOptions(string $type, array $item): bool
    {
        if (!in_array($type, [ExerciseSet::TYPE_MULTIPLE_CHOICE, ExerciseSet::TYPE_FILL_BLANK], true)) {
            return false;
        }

        $answer = mb_strtolower(trim($type === ExerciseSet::TYPE_MULTIPLE_CHOICE
            ? (string) ($item['options'][$item['answer_index'] ?? 0] ?? '')
            : (string) ($item['answer'] ?? '')));

        $distractors = array_map(
            static fn (string $w): string => mb_strtolower(trim($w)),
            $this->distractorsOf($type, $item)
        );

        foreach (self::INTERCHANGEABLE_SETS as $name => $set) {
            if (!in_array($answer, $set, true)) {
                continue;
            }

            $clash = array_intersect($distractors, $set);

            if ($clash === []) {
                continue;
            }

            if ($name === 'subject_pronouns') {
                // Agreement carve-out: with a be-form in the frame, only
                // the agreeing pronouns compete. A distractor from a
                // DIFFERENT agreement group is honestly wrong.
                $frame = mb_strtolower($this->frameWith($type, $item, '___'));

                foreach (self::BE_AGREEMENT as $verb => $group) {
                    if (preg_match('/\b' . $verb . '\b/u', $frame) === 1
                        && in_array($answer, $group, true)) {
                        // Ambiguous only if a distractor shares the group.
                        return array_intersect($clash, $group) !== [];
                    }
                }
            }

            return true;
        }

        return false;
    }

    private function usesMetalanguage(string $type, array $item): bool
    {
        $words = match ($type) {
            ExerciseSet::TYPE_MULTIPLE_CHOICE => $item['options'] ?? [],
            ExerciseSet::TYPE_FILL_BLANK      => $item['word_bank'] ?? [],
            default                           => [],
        };

        foreach ($words as $word) {
            if (in_array(mb_strtolower(trim((string) $word)), self::METALANGUAGE, true)) {
                return true;
            }
        }

        return false;
    }

    /* ------------------------------------------------------------- G8 */

    /**
     * Second-model review of every surviving item (docs/05 stage G8).
     *
     * @param  list<array{item: array, source: ?array, ratio: ?float}> $kept
     * @return array{0: list<array>, 1: list<array>} [kept, dropped]
     */
    private function judgePass(string $type, array $kept): array
    {
        $lines = '';

        foreach ($kept as $i => $entry) {
            $item = $entry['item'];

            $lines .= match ($type) {
                ExerciseSet::TYPE_MULTIPLE_CHOICE => sprintf(
                    "%d. STEM: %s | ANSWER: %s | WRONG OPTIONS: %s\n",
                    $i,
                    $item['stem'],
                    $item['options'][$item['answer_index']] ?? '',
                    implode(', ', $this->distractorsOf($type, $item))
                ),
                ExerciseSet::TYPE_FILL_BLANK => sprintf(
                    "%d. SENTENCE: %s___%s | ANSWER: %s | WRONG OPTIONS: %s\n",
                    $i,
                    $item['before'] ?? '',
                    $item['after'] ?? '',
                    $item['answer'] ?? '',
                    implode(', ', $this->distractorsOf($type, $item))
                ),
                default => sprintf(
                    "%d. SENTENCE: %s\n",
                    $i,
                    implode(' ', $item['tokens'] ?? [])
                ),
            };
        }

        $system = <<<'PROMPT'
You are a strict reviewer of English exercises for absolute beginners.
For each numbered item decide ok=true ONLY if ALL of these hold:

1. The sentence with the answer in place is natural, meaningful English
   that a beginner textbook could print. "Practise with other names" or
   "Nice to meet him" as a greeting are NOT natural.
2. EVERY wrong option, put into the gap, produces a sentence that is
   clearly wrong — ungrammatical or obviously senseless. If even one
   wrong option makes an acceptable sentence, ok=false. "See ___ on
   Friday" with us/me/them/him has four right answers: ok=false.
3. For word-order items: the words allow exactly ONE natural order.

Judge every item independently. Be strict: when unsure, ok=false.
Return strict JSON only.
PROMPT;

        $schema = [
            'type'       => 'OBJECT',
            'properties' => [
                'verdicts' => [
                    'type'  => 'ARRAY',
                    'items' => [
                        'type'       => 'OBJECT',
                        'properties' => [
                            'index'  => ['type' => 'INTEGER'],
                            'ok'     => ['type' => 'BOOLEAN'],
                            'reason' => ['type' => 'STRING'],
                        ],
                        'required' => ['index', 'ok'],
                    ],
                ],
            ],
            'required' => ['verdicts'],
        ];

        $result = $this->judge->complete($system, "ITEMS\n{$lines}", $schema, 0.0);

        $verdicts = [];
        foreach ($result->json['verdicts'] ?? [] as $verdict) {
            if (is_int($verdict['index'] ?? null)) {
                $verdicts[$verdict['index']] = $verdict;
            }
        }

        $passed = [];
        $dropped = [];

        foreach ($kept as $i => $entry) {
            $verdict = $verdicts[$i] ?? null;

            // No verdict is a failed review, not a pass — the judge
            // exists precisely because the generator cannot be trusted
            // to grade itself.
            if ($verdict === null || ($verdict['ok'] ?? false) !== true) {
                $dropped[] = [
                    'reason' => 'G8_judge',
                    'detail' => (string) ($verdict['reason'] ?? 'no verdict'),
                    'item'   => $entry['item'],
                ];
                continue;
            }

            $passed[] = $entry;
        }

        return [$passed, $dropped];
    }

    /* ---------------------------------------------------- match pairs */

    /**
     * Match-pairs items come straight from the section's vocabulary
     * list — term on the left, the student's-language translation on the
     * right. Deterministic: the vocabulary is already reviewed content
     * (FR-6.2), the relation is unambiguous, and no model is involved.
     *
     * The generated pairs it replaces had no discernible relation at
     * all — "tree → house" is not an exercise, it is a guessing game.
     *
     * @return list<array{left: string, right_tk: string, right_ru: string}>
     */
    public function buildPairsFromVocabulary(UnitSection $section, int $maxPairs): array
    {
        $rows = Capsule::table('vocabulary_items')
            ->where('unit_section_id', $section->id)
            ->orderBy('sort_order')
            ->get();

        $pairs = [];
        $seenRight = [];

        foreach ($rows as $row) {
            $tk = trim((string) $row->translation_tk);
            $ru = trim((string) $row->translation_ru);
            $en = trim((string) $row->term_en);

            if ($en === '' || ($tk === '' && $ru === '')) {
                continue;
            }

            // Vocabulary proposals occasionally pick up grammar-bank
            // terminology from the page. It is cleaned at the source
            // too, but pairs must never show it regardless.
            if (in_array(mb_strtolower($en), self::METALANGUAGE, true)) {
                continue;
            }

            // Two vocabulary entries can share a translation ("nice" and
            // "good" both ýagşy). Matching would then have two right
            // targets, so only the first such pair is used.
            $rightKey = mb_strtolower($tk . '|' . $ru);

            if (isset($seenRight[$rightKey])) {
                continue;
            }

            $seenRight[$rightKey] = true;
            $pairs[] = ['left' => $en, 'right_tk' => $tk, 'right_ru' => $ru];

            if (count($pairs) >= $maxPairs) {
                break;
            }
        }

        return $pairs;
    }

    private function hasValidShape(string $type, array $item): bool
    {
        switch ($type) {
            case ExerciseSet::TYPE_MULTIPLE_CHOICE:
                $options = array_map(
                    static fn ($o): string => mb_strtolower(trim((string) $o)),
                    $item['options'] ?? []
                );
                $answer = $item['answer_index'] ?? null;

                return count($options) === 4
                    && count(array_unique($options)) === 4
                    && is_int($answer) && $answer >= 0 && $answer <= 3
                    && str_contains((string) ($item['stem'] ?? ''), '___');

            case ExerciseSet::TYPE_FILL_BLANK:
                $answer = mb_strtolower(trim((string) ($item['answer'] ?? '')));
                $bank = array_map(
                    static fn ($w): string => mb_strtolower(trim((string) $w)),
                    $item['word_bank'] ?? []
                );

                return $answer !== ''
                    && count($bank) >= 3 && count($bank) <= 8
                    && count(array_unique($bank)) === count($bank)
                    && in_array($answer, $bank, true);

            case ExerciseSet::TYPE_REORDER:
                $tokens = $item['tokens'] ?? [];

                return count($tokens) >= 4 && count($tokens) <= 14;

            case ExerciseSet::TYPE_MATCH_PAIRS:
                return trim((string) ($item['left'] ?? '')) !== ''
                    && trim((string) ($item['right'] ?? '')) !== '';
        }

        return false;
    }

    private function renderForComparison(string $type, array $item): string
    {
        return match ($type) {
            ExerciseSet::TYPE_MULTIPLE_CHOICE => str_replace(
                '___',
                (string) ($item['options'][$item['answer_index'] ?? 0] ?? ''),
                (string) ($item['stem'] ?? '')
            ),
            ExerciseSet::TYPE_FILL_BLANK => trim(
                ($item['before'] ?? '') . ' ' . ($item['answer'] ?? '') . ' ' . ($item['after'] ?? '')
            ),
            ExerciseSet::TYPE_REORDER => implode(' ', $item['tokens'] ?? []),
            default => '',
        };
    }

    private function systemPrompt(string $type): string
    {
        $shared = <<<'PROMPT'
You rewrite existing textbook sentences into exercises for beginner
learners of English. You NEVER write a sentence from scratch.

For every item you MUST:
  - choose exactly one SOURCE SENTENCE and return its index
  - change 20-25% of its words - no less, no more
  - swap LIKE FOR LIKE so the result stays natural: a name for another
    name, a day for another day, a place for a place, a drink for a
    drink. The new sentence must be something a real person would say.
    "See you on Monday" is a good rewrite of "See you on Friday";
    "Practise with other names" is garbage and forbidden.
  - use only words from ALLOWED VOCABULARY plus ordinary function words
  - stay within grammar a beginner has met: present simple and the verb
    "be" only. No past tenses, no perfect tenses, no modals.

Spread the items across the SOURCE SENTENCES. Use each source sentence at
most TWICE, and when you use one twice, target a different word each time
so the learner is not shown the same gap again.

FORBIDDEN:
  - inventing an item with no source index
  - using any source index more than twice
  - a rewrite that changes what kind of thing the sentence says
    ("meet you" -> "meet him" turns a greeting into nonsense)
  - content taken from listening, reading or speaking tasks
  - any word outside ALLOWED VOCABULARY except function words
  - grammar terms (singular, verb, noun...) anywhere inside an item

Return strict JSON only.
PROMPT;

        $gapRules = <<<'PROMPT'
GAP RULES (these decide whether the item is usable at all):
  - The gapped word must be a word you KEPT from the source sentence,
    never a word you changed. The student is being tested on the book's
    language, so the book's own word must be the right answer.
  - Test each wrong option by reading the full sentence with it in the
    gap. If the sentence is grammatical and makes sense, the option is
    NOT wrong - throw it away and pick another. There must be EXACTLY
    one correct option.
  - Never build the options from words that mean the same kind of thing
    in the same slot (my/your/our/their, me/him/us/them, here/there,
    days of the week). Those are all correct at once. Prefer options
    that differ in word class or break the grammar of the sentence.
  - Wrong options come from ALLOWED VOCABULARY and must be single words.
PROMPT;

        return $shared . "\n\n" . match ($type) {
            ExerciseSet::TYPE_MULTIPLE_CHOICE =>
                "Work in two steps for each item.\n"
                . "STEP 1: copy the SOURCE SENTENCE and change only 1 or 2 words,\n"
                . "        like for like. Every other word stays exactly as it was.\n"
                . "STEP 2: replace ONE of the KEPT words with ___ to make the stem.\n"
                . "Give four options; the correct one is the word you removed.\n\n"
                . $gapRules,
            ExerciseSet::TYPE_FILL_BLANK =>
                "Work in two steps for each item.\n"
                . "STEP 1: copy the SOURCE SENTENCE and change only 1 or 2 words,\n"
                . "        like for like. Every other word stays exactly as it was.\n"
                . "STEP 2: remove ONE of the KEPT words. Return: text before the\n"
                . "        gap, that word as the answer, text after the gap.\n"
                . "Add a word bank of four options containing the answer.\n\n"
                . $gapRules,
            ExerciseSet::TYPE_REORDER =>
                "Work in two steps for each item.\n"
                . "STEP 1: copy the SOURCE SENTENCE and change only 1 or 2 words,\n"
                . "        like for like. Every other word stays exactly as it was.\n"
                . "        The result must be natural spoken English.\n"
                . "STEP 2: split that new sentence into its words, in the CORRECT order.\n"
                . "Return the word list from step 2. Between 5 and 12 words.\n"
                . "Exactly one ordering may be grammatical. If the words could form\n"
                . 'two different correct sentences, discard the item.',
            default => '',
        };
    }

    private function schemaFor(string $type): array
    {
        $properties = match ($type) {
            ExerciseSet::TYPE_MULTIPLE_CHOICE => [
                'source_index' => ['type' => 'INTEGER'],
                'stem'         => ['type' => 'STRING'],
                'options'      => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
                'answer_index' => ['type' => 'INTEGER'],
            ],
            ExerciseSet::TYPE_FILL_BLANK => [
                'source_index' => ['type' => 'INTEGER'],
                'before'       => ['type' => 'STRING'],
                'answer'       => ['type' => 'STRING'],
                'after'        => ['type' => 'STRING'],
                'word_bank'    => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
            ],
            ExerciseSet::TYPE_REORDER => [
                'source_index' => ['type' => 'INTEGER'],
                'tokens'       => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
            ],
            ExerciseSet::TYPE_MATCH_PAIRS => [
                'left'  => ['type' => 'STRING'],
                'right' => ['type' => 'STRING'],
            ],
            default => [],
        };

        return [
            'type'       => 'OBJECT',
            'properties' => [
                'items' => [
                    'type'  => 'ARRAY',
                    'items' => [
                        'type'       => 'OBJECT',
                        'properties' => $properties,
                        'required'   => array_keys($properties),
                    ],
                ],
            ],
            'required' => ['items'],
        ];
    }
}
