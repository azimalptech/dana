<?php

declare(strict_types=1);

namespace Dana\Domain\Content;

use Dana\Http\ApiException;
use Dana\Domain\Models\ExerciseSet;

/**
 * The one gate every question payload passes through before it is
 * stored — from the editor (ContentAdminController::saveQuestion) and
 * from the xlsx import (XlsxImportService) alike. The Grader trusts the
 * stored payload blindly, so whatever wrote it must have come through
 * here: a payload that skipped validation could carry a `reorder`
 * answer that disagrees with its tokens, a multiple_choice answer
 * outside its options, or a match_pairs entry the student can never
 * complete — and every submission would grade wrong with no
 * author-visible error.
 *
 * Two generations of shapes are accepted (docs/06-CONTENT-V2.md §2):
 *
 *  - multiple_choice: legacy `{stem: "…", options: ["…"×4], answer}` OR
 *    v2 part-objects — stem and each of 2–4 options is exactly one of
 *    `{text}`, `{audio_note, media_path}`, `{image_note, media_path}`.
 *  - fill_letter_space: legacy `{text: "… {word} …", reveal}` OR v2
 *    `{mask: "S<e>ve<n>"}` — letters inside `<>` are the hidden ones.
 *  - reorder: `{tokens}` — token order IS the answer; the v2 shape
 *    stores no separate answer array (the legacy `answer` range is
 *    accepted on input and simply not re-stored).
 *  - fill_blank / match_pairs: one shape, unchanged (fill_blank gains
 *    the v2 bank size bound of 2–6).
 *
 * assert() refuses a bad payload with a bilingual validation message
 * (the import turns it into a row-level error); normalise() then
 * rebuilds the stored shape from the validated fields rather than
 * storing what was sent, exactly as the editor always has.
 */
final class QuestionPayload
{
    /** @param array<mixed> $payload */
    public static function assert(string $type, array $payload): void
    {
        $fail = fn (string $tk, string $ru) => throw ApiException::validation($tk, $ru);

        switch ($type) {
            case ExerciseSet::TYPE_MULTIPLE_CHOICE:
                $options = $payload['options'] ?? [];

                if (!is_array($options)) {
                    $fail('Sorag maglumaty nädogry.', 'Некорректные данные вопроса.');
                }

                if (self::isPartOptions($options)) {
                    self::assertMultipleChoiceV2($payload, $options, $fail);
                    break;
                }

                if (count($options) !== 4) {
                    $fail('Dört jogap warianty gerek.', 'Нужно ровно 4 варианта ответа.');
                }

                foreach ($options as $option) {
                    // A nested array here would grade as the string
                    // "Array" — a spreadsheet can send anything.
                    if (!is_scalar($option)) {
                        $fail('Sorag maglumaty nädogry.', 'Некорректные данные вопроса.');
                    }
                }

                if (!isset($payload['answer']) || !is_int($payload['answer'])
                    || $payload['answer'] < 0 || $payload['answer'] > 3) {
                    $fail('Dogry jogaby saýlaň.', 'Укажите правильный вариант.');
                }

                if (count(array_unique(array_map('strval', $options))) !== 4) {
                    $fail('Warianty gaýtalanmaly däl.', 'Варианты не должны повторяться.');
                }

                if (isset($payload['stem']) && !is_scalar($payload['stem'])) {
                    $fail('Sorag maglumaty nädogry.', 'Некорректные данные вопроса.');
                }
                break;

            case ExerciseSet::TYPE_FILL_BLANK:
                $answer = $payload['answer'] ?? [];

                if ($answer === [] || !is_array($answer) || !isset($answer[0]) || !is_scalar($answer[0])) {
                    $fail('Dogry jogaby giriziň.', 'Укажите правильный ответ.');
                }

                $bank = $payload['word_bank'] ?? null;

                if (!is_array($bank) || !in_array($answer[0], $bank, true)) {
                    $fail(
                        'Dogry jogap sözler toplumynda bolmaly.',
                        'Правильный ответ должен быть в наборе слов.'
                    );
                }

                // v2 bound (§2): 2–6 words — one lone word is no choice,
                // seven will not fit the app's bank layout.
                if (count($bank) < 2 || count($bank) > 6) {
                    $fail(
                        'Sözler toplumynda 2–6 söz bolmaly.',
                        'В наборе слов должно быть от 2 до 6 слов.'
                    );
                }

                foreach ($bank as $word) {
                    if (!is_scalar($word)) {
                        $fail('Sorag maglumaty nädogry.', 'Некорректные данные вопроса.');
                    }
                }

                foreach (['before', 'after'] as $side) {
                    if (isset($payload[$side]) && !is_scalar($payload[$side])) {
                        $fail('Sorag maglumaty nädogry.', 'Некорректные данные вопроса.');
                    }
                }
                break;

            case ExerciseSet::TYPE_REORDER:
                $tokens = $payload['tokens'] ?? [];

                // v2 floor: two tokens are already a puzzle ("Monday
                // Tuesday"); the client's files use as few as three.
                if (!is_array($tokens) || count($tokens) < 2) {
                    $fail('Azyndan 2 söz gerek.', 'Нужно минимум 2 слова.');
                }

                foreach ($tokens as $token) {
                    if (!is_scalar($token)) {
                        $fail('Sorag maglumaty nädogry.', 'Некорректные данные вопроса.');
                    }
                }
                break;

            case ExerciseSet::TYPE_MATCH_PAIRS:
                $pairs = $payload['pairs'] ?? [];

                if (!is_array($pairs)
                    || count($pairs) < ExerciseSet::MIN_PAIRS || count($pairs) > ExerciseSet::MAX_PAIRS) {
                    $fail(
                        'Jübüt sany 4-5 bolmaly.',
                        'Количество пар должно быть от 4 до 5.'
                    );
                }

                foreach ($pairs as $pair) {
                    if (!is_array($pair)) {
                        $fail('Sorag maglumaty nädogry.', 'Некорректные данные вопроса.');
                    }

                    foreach (['left', 'right', 'right_tk', 'right_ru'] as $key) {
                        if (isset($pair[$key]) && !is_scalar($pair[$key])) {
                            $fail('Sorag maglumaty nädogry.', 'Некорректные данные вопроса.');
                        }
                    }
                }

                // The grader answers by a {left => right} map, so two
                // pairs whose left sides collapse to the same key (case-
                // and space-insensitive, matching normalise()) become one
                // entry and no submission can ever count them all — the
                // question would cap every student below 100% with no
                // author-visible error. Refuse it at authoring time.
                $lefts = array_map(
                    static fn ($p): string =>
                        mb_strtolower(trim((string) ($p['left'] ?? ''))),
                    $pairs
                );
                if (count($lefts) !== count(array_unique($lefts))) {
                    $fail(
                        'Sol tarapdaky sözler gaýtalanmaly däl.',
                        'Слова в левой колонке не должны повторяться.'
                    );
                }
                break;

            case ExerciseSet::TYPE_FILL_LETTER_SPACE:
                if (array_key_exists('mask', $payload)) {
                    // v2 (§2): `S<e>ve<n>` — `<>` marks HIDDEN letters at
                    // arbitrary positions. The mask IS the answer key.
                    if (!is_scalar($payload['mask'])) {
                        $fail('Sorag maglumaty nädogry.', 'Некорректные данные вопроса.');
                    }

                    $mask = trim((string) $payload['mask']);

                    if ($mask === '' || preg_match('/<[^<>]+>/u', $mask) !== 1) {
                        $fail(
                            'Maskada azyndan bir <gizlin harp> bölegi gerek.',
                            'В маске нужна хотя бы одна скрытая часть в <>.'
                        );
                    }

                    // A stray bracket would desynchronise the boxes the
                    // student sees from the letters the grader expects.
                    $rest = (string) preg_replace('/<[^<>]+>/u', '', $mask);

                    if (str_contains($rest, '<') || str_contains($rest, '>')) {
                        $fail(
                            'Maskadaky <> belgileri jübüt bolmaly.',
                            'Скобки <> в маске должны быть парными.'
                        );
                    }
                    break;
                }

                // FR-13.21 (legacy): plain text with {word} marks + a
                // reveal count. The blanks are re-derived from the text
                // at serve/grade time, so the text IS the answer key.
                if (isset($payload['text']) && !is_scalar($payload['text'])) {
                    $fail('Sorag maglumaty nädogry.', 'Некорректные данные вопроса.');
                }

                $text = trim((string) ($payload['text'] ?? ''));

                if ($text === '' || preg_match('/\{[^{}]+\}/u', $text) !== 1) {
                    $fail(
                        'Tekstde azyndan bir {söz} bellik gerek.',
                        'В тексте нужна хотя бы одна отметка {слово}.'
                    );
                }

                $reveal = $payload['reveal'] ?? 0;

                if (!is_int($reveal) && !(is_string($reveal) && ctype_digit($reveal))) {
                    $fail('Açyljak harp sany nädogry.', 'Неверное число открытых букв.');
                }
                break;
        }
    }

    /**
     * The stored payload is what the Grader trusts, so it is rebuilt
     * from the validated fields rather than stored as-sent. Without
     * this, a crafted reorder payload could carry an `answer` that
     * disagrees with its tokens and every submission would grade wrong.
     * Call assert() first — the shapes here are only safe after it.
     *
     * @param array<mixed> $payload
     * @return array<string, mixed>
     */
    public static function normalise(string $type, array $payload): array
    {
        return match ($type) {
            ExerciseSet::TYPE_MULTIPLE_CHOICE => self::isPartOptions($payload['options'])
                ? [
                    'stem'    => self::normalisePart($payload['stem']),
                    'options' => array_map(self::normalisePart(...), array_values($payload['options'])),
                    // The ORIGINAL index — 0 straight from the files,
                    // where Option A is always the correct one (§1.1).
                    'answer'  => (int) $payload['answer'],
                ]
                : [
                    'stem'    => (string) ($payload['stem'] ?? ''),
                    'options' => array_map('strval', array_values($payload['options'])),
                    'answer'  => (int) $payload['answer'],
                ],
            ExerciseSet::TYPE_FILL_BLANK => [
                'before'    => (string) ($payload['before'] ?? ''),
                'after'     => (string) ($payload['after'] ?? ''),
                'answer'    => [(string) $payload['answer'][0]],
                'word_bank' => array_map('strval', array_values($payload['word_bank'] ?? [])),
            ],
            // v2 (§2): token order IS the answer — no separate answer
            // array is stored, whatever the input carried. The Grader
            // derives the identity order when the key is absent.
            ExerciseSet::TYPE_REORDER => [
                'tokens' => array_map('strval', array_values($payload['tokens'])),
            ],
            ExerciseSet::TYPE_MATCH_PAIRS => [
                'pairs' => array_values(array_map(
                    static fn (array $pair): array => array_filter([
                        'left'     => (string) ($pair['left'] ?? ''),
                        'right'    => isset($pair['right']) ? (string) $pair['right'] : null,
                        'right_tk' => isset($pair['right_tk']) ? (string) $pair['right_tk'] : null,
                        'right_ru' => isset($pair['right_ru']) ? (string) $pair['right_ru'] : null,
                    ], static fn ($v): bool => $v !== null),
                    $payload['pairs']
                )),
            ],
            ExerciseSet::TYPE_FILL_LETTER_SPACE => array_key_exists('mask', $payload)
                // v2 (§2): only the mask is stored — visible letters,
                // boxes and the hidden answer all derive from it.
                ? ['mask' => trim((string) $payload['mask'])]
                // FR-13.21 (legacy): the marked text and the reveal
                // count — blanks derive from them (Question::letterBlanks).
                : [
                    'text'   => trim((string) $payload['text']),
                    'reveal' => max(0, (int) ($payload['reveal'] ?? 0)),
                ],
            default => $payload,
        };
    }

    // ------------------------------------------------------------- v2 parts

    /** v2 when the options are part-objects; legacy when scalars. */
    private static function isPartOptions(mixed $options): bool
    {
        return is_array($options) && $options !== [] && is_array(reset($options));
    }

    /**
     * @param array<mixed> $payload
     * @param array<mixed> $options
     * @param callable(string, string): never $fail
     */
    private static function assertMultipleChoiceV2(array $payload, array $options, callable $fail): void
    {
        // 2–4 options (§1.1 — real Test rows carry as few as two).
        if (count($options) < 2 || count($options) > 4) {
            $fail('Jogap wariantlary 2–4 bolmaly.', 'Нужно от 2 до 4 вариантов ответа.');
        }

        self::assertPart($payload['stem'] ?? null, $fail);

        $keys = [];

        foreach ($options as $option) {
            $keys[] = self::assertPart($option, $fail);
        }

        if (count(array_unique($keys)) !== count($keys)) {
            $fail('Warianty gaýtalanmaly däl.', 'Варианты не должны повторяться.');
        }

        if (!isset($payload['answer']) || !is_int($payload['answer'])
            || $payload['answer'] < 0 || $payload['answer'] >= count($options)) {
            $fail('Dogry jogaby saýlaň.', 'Укажите правильный вариант.');
        }
    }

    /**
     * One v2 part: exactly one of text / audio_note / image_note, a
     * non-empty scalar; media_path (audio/image only) scalar or null.
     * Returns a comparison key for the duplicate-option check.
     *
     * @param callable(string, string): never $fail
     */
    private static function assertPart(mixed $part, callable $fail): string
    {
        if (!is_array($part)) {
            $fail('Sorag maglumaty nädogry.', 'Некорректные данные вопроса.');
        }

        $present = array_values(array_intersect(
            ['text', 'audio_note', 'image_note'],
            array_keys($part)
        ));

        if (count($present) !== 1
            || !is_scalar($part[$present[0]])
            || trim((string) $part[$present[0]]) === '') {
            $fail(
                'Sorag böleginde text, audio_note ýa-da image_note-dan diňe biri bolmaly.',
                'В части вопроса должно быть заполнено ровно одно из text, audio_note, image_note.'
            );
        }

        if (array_key_exists('media_path', $part)
            && $part['media_path'] !== null && !is_scalar($part['media_path'])) {
            $fail('Sorag maglumaty nädogry.', 'Некорректные данные вопроса.');
        }

        return $present[0] . ':' . mb_strtolower(trim((string) $part[$present[0]]));
    }

    /**
     * The stored shape of a validated v2 part — the used member plus, for
     * audio/image, its media_path (null until the panel uploads a file).
     *
     * @param array<string, mixed> $part
     * @return array<string, mixed>
     */
    private static function normalisePart(array $part): array
    {
        if (isset($part['text'])) {
            return ['text' => (string) $part['text']];
        }

        $kind = isset($part['audio_note']) ? 'audio_note' : 'image_note';
        $path = $part['media_path'] ?? null;

        return [
            $kind        => (string) $part[$kind],
            'media_path' => $path === null || $path === '' ? null : (string) $path,
        ];
    }
}
