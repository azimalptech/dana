<?php

declare(strict_types=1);

namespace Dana\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * FR-4.14: prompt_tk / prompt_ru carry the INSTRUCTION only. The English
 * content being practised lives in `payload` and stays English.
 *
 * §13 additions: `question_type` is the media type (text/audio/picture,
 * FR-13.13) and `quiz_eligible` marks a question for the child unit's
 * Exam Quiz (FR-13.14).
 */
final class Question extends Model
{
    protected $table = 'questions';

    protected $fillable = [
        'exercise_set_id', 'question_type', 'media_path', 'quiz_eligible',
        'sort_order', 'prompt_tk', 'prompt_ru', 'payload',
        'is_human_edited', 'is_active',
    ];

    protected $casts = [
        'payload'         => 'array',
        'quiz_eligible'   => 'boolean',
        'is_human_edited' => 'boolean',
        'is_active'       => 'boolean',
        'sort_order'      => 'integer',
    ];

    public function exerciseSet(): BelongsTo
    {
        return $this->belongsTo(ExerciseSet::class);
    }

    /** Media parts (audio/image) are served to the app as this URL prefix (§2). */
    public const MEDIA_URL_PREFIX = '/api/v1/media/';

    /**
     * The payload as the student should receive it — with the answer
     * removed. Grading happens server-side (NFR-5), so the client is
     * never told what the right answer is.
     *
     * v2 (docs/06-CONTENT-V2.md §2/§3): audio/image parts leave the
     * server as `{kind, url}` only — the authoring NOTE ("seven",
     * "IMG_PEN") never reaches a student, and neither does the answer
     * index, the reorder order or the hidden letters of a letter mask.
     */
    public function payloadForStudent(string $type, string $lang = 'tk'): array
    {
        $payload = $this->payload ?? [];

        return match ($type) {
            ExerciseSet::TYPE_MULTIPLE_CHOICE => [
                // Text stem stays a string; a media stem becomes {kind, url}
                // (the note is stripped). The answer is never sent.
                'stem'    => self::stemForStudent($payload['stem'] ?? ''),
                // FR-12.6: shuffled on every attempt, each option carrying
                // its ORIGINAL index `i`. The client submits that index (or,
                // for legacy text content, the option text); the stored
                // order and the answer stay server-side.
                'options' => self::shuffled(self::optionsForStudent($payload['options'] ?? [])),
            ],
            ExerciseSet::TYPE_FILL_BLANK => [
                'before'    => $payload['before'] ?? '',
                'after'     => $payload['after'] ?? '',
                // Unshuffled, the answer tends to sit first in the bank —
                // the generator emits it that way — and students learn
                // "tap the first word" instead of the word.
                'word_bank' => self::shuffled($payload['word_bank'] ?? []),
            ],
            ExerciseSet::TYPE_REORDER => [
                // Shuffled here; the correct ordering never leaves the server.
                'tokens' => self::scrambled($payload['tokens'] ?? []),
            ],
            ExerciseSet::TYPE_MATCH_PAIRS => [
                'left'  => array_column($payload['pairs'] ?? [], 'left'),
                'right' => self::shuffled(self::rightSides($payload['pairs'] ?? [], $lang)),
            ],
            // FR-13.21 / §2: parts array — plain text runs plus per-blank
            // {count} (v2 mask) or {given, count} (legacy reveal). The
            // hidden letters NEVER leave the server; the client only learns
            // how many boxes to draw.
            ExerciseSet::TYPE_FILL_LETTER_SPACE => [
                'parts' => array_key_exists('mask', $payload)
                    ? self::maskParts((string) $payload['mask'])
                    : self::letterSpaceParts($payload),
            ],
            default => [],
        };
    }

    // --------------------------------------------------------- servability

    /**
     * §3: a question is servable only when every audio/image part it
     * carries has an uploaded file (media_path). A question with a pending
     * media note is auto-hidden — from section play, quiz pools, quiz
     * draws and every percentage denominator — until the file arrives.
     * Legacy and text-only payloads have no media parts and are always
     * servable. `is_active` is enforced separately in the repository layer.
     *
     * @param mixed $payload the decoded payload (array), or anything else
     */
    public static function payloadServable(mixed $payload): bool
    {
        if (!is_array($payload)) {
            return true;
        }

        foreach (self::mediaParts($payload) as $part) {
            if (empty($part['media_path'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Every audio/image part of a v2 payload, each as
     * `{part, kind, note, media_path}` — `part` ∈ stem, opt0..opt3, `kind`
     * ∈ audio, image. The panel lists these for the upload queue; the
     * serve/grade path reads their media_path for servability.
     *
     * @param array<string, mixed> $payload
     * @return list<array{part: string, kind: string, note: string, media_path: ?string}>
     */
    public static function mediaParts(array $payload): array
    {
        $out = [];

        $collect = static function (string $name, mixed $part) use (&$out): void {
            if (!is_array($part)) {
                return;
            }

            $kind = isset($part['audio_note']) ? 'audio' : (isset($part['image_note']) ? 'image' : null);

            if ($kind === null) {
                return; // a text part carries no media
            }

            $out[] = [
                'part'       => $name,
                'kind'       => $kind,
                'note'       => (string) ($part['audio_note'] ?? $part['image_note']),
                'media_path' => isset($part['media_path']) && $part['media_path'] !== ''
                    ? (string) $part['media_path']
                    : null,
            ];
        };

        $collect('stem', $payload['stem'] ?? null);

        foreach (array_values($payload['options'] ?? []) as $i => $option) {
            $collect('opt' . $i, $option);
        }

        return $out;
    }

    // --------------------------------------------------- mc for the student

    /** A stem the student may see: a plain string, or {kind, url} for media. */
    private static function stemForStudent(mixed $stem): mixed
    {
        if (is_array($stem)) {
            if (isset($stem['text'])) {
                return (string) $stem['text'];
            }

            $kind = isset($stem['audio_note']) ? 'audio' : (isset($stem['image_note']) ? 'image' : null);

            if ($kind !== null) {
                return ['kind' => $kind, 'url' => self::mediaUrl($stem['media_path'] ?? null)];
            }
        }

        return (string) $stem;
    }

    /**
     * Options as the student receives them, each tagged with its ORIGINAL
     * index so the shuffle carries no information: `{i, text}` for text (and
     * legacy string) options, `{i, kind, url}` for media ones. The answer
     * index and every authoring note stay server-side.
     *
     * @param array<mixed> $options
     * @return list<array<string, mixed>>
     */
    private static function optionsForStudent(array $options): array
    {
        $out = [];

        foreach (array_values($options) as $i => $option) {
            if (is_array($option)) {
                if (isset($option['text'])) {
                    $out[] = ['i' => $i, 'text' => (string) $option['text']];
                    continue;
                }

                $kind = isset($option['audio_note']) ? 'audio' : (isset($option['image_note']) ? 'image' : null);

                if ($kind !== null) {
                    $out[] = ['i' => $i, 'kind' => $kind, 'url' => self::mediaUrl($option['media_path'] ?? null)];
                    continue;
                }
            }

            // Legacy scalar option — the text itself.
            $out[] = ['i' => $i, 'text' => (string) $option];
        }

        return $out;
    }

    /** The authenticated streaming URL for a stored media_path, '' when absent. */
    private static function mediaUrl(mixed $path): string
    {
        $path = (string) ($path ?? '');

        return $path === '' ? '' : self::MEDIA_URL_PREFIX . basename($path);
    }

    // --------------------------------------------------- letter mask (v2)

    /**
     * The hidden letters of a v2 mask, in order — one entry per `<…>`
     * group. `S<e>ve<n>` → ['e', 'n']; `S<even>` → ['even']. This is the
     * answer key (grader-only); it never reaches a student.
     *
     * @return list<string>
     */
    public static function maskBlanks(string $mask): array
    {
        $blanks = [];

        foreach (self::maskSegments($mask) as [$isHidden, $chunk]) {
            if ($isHidden) {
                $blanks[] = $chunk;
            }
        }

        return $blanks;
    }

    /**
     * The student-facing rendering of a v2 mask: `{t}` for a visible run,
     * `{blank: {count}}` for each hidden group (count = its letter count).
     * The hidden letters themselves are omitted.
     *
     * @return list<array<string, mixed>>
     */
    private static function maskParts(string $mask): array
    {
        $parts = [];

        foreach (self::maskSegments($mask) as [$isHidden, $chunk]) {
            $parts[] = $isHidden
                ? ['blank' => ['count' => mb_strlen($chunk)]]
                : ['t' => $chunk];
        }

        return $parts;
    }

    /**
     * Splits `S<e>ve<n>` into ordered [isHidden, chunk] segments — visible
     * runs and `<…>` groups. Empty visible runs (two adjacent groups, a
     * group at the very start) are dropped.
     *
     * @return list<array{0: bool, 1: string}>
     */
    private static function maskSegments(string $mask): array
    {
        $pieces = preg_split('/<([^<>]+)>/u', $mask, -1, PREG_SPLIT_DELIM_CAPTURE);

        if ($pieces === false) {
            return [];
        }

        $out = [];

        foreach ($pieces as $i => $piece) {
            // preg_split alternates visible text / captured hidden group.
            $isHidden = $i % 2 === 1;

            if (!$isHidden && $piece === '') {
                continue;
            }

            $out[] = [$isHidden, $piece];
        }

        return $out;
    }

    /**
     * Splits an authored fill_letter_space payload into its blanks.
     *
     * Authored shape (FR-13.21): `{"text": "I just {want} to go.",
     * "reveal": 2}` — {} marks a target word, `reveal` is how many
     * leading letters stay visible in every blank.
     *
     * @return list<array{word: string, given: string, missing: string}>
     */
    public static function letterBlanks(array $payload): array
    {
        $reveal = max(0, (int) ($payload['reveal'] ?? 0));
        $blanks = [];

        foreach (self::letterSpaceSplit((string) ($payload['text'] ?? '')) as [$isBlank, $chunk]) {
            if (!$isBlank) {
                continue;
            }

            // A reveal longer than the word would ask for a negative
            // number of letters; clamp so the blank degrades to "all
            // shown, nothing to type" instead of breaking.
            $shown = min($reveal, mb_strlen($chunk));

            $blanks[] = [
                'word'    => $chunk,
                'given'   => mb_substr($chunk, 0, $shown),
                'missing' => mb_substr($chunk, $shown),
            ];
        }

        return $blanks;
    }

    /**
     * The student-facing parts array: `{"t": "I just "}` for text runs,
     * `{"blank": {"given": "wa", "count": 2}}` for each target.
     *
     * @return list<array<string, mixed>>
     */
    private static function letterSpaceParts(array $payload): array
    {
        $reveal = max(0, (int) ($payload['reveal'] ?? 0));
        $parts = [];

        foreach (self::letterSpaceSplit((string) ($payload['text'] ?? '')) as [$isBlank, $chunk]) {
            if (!$isBlank) {
                $parts[] = ['t' => $chunk];
                continue;
            }

            $shown = min($reveal, mb_strlen($chunk));

            $parts[] = ['blank' => [
                'given' => mb_substr($chunk, 0, $shown),
                'count' => mb_strlen($chunk) - $shown,
            ]];
        }

        return $parts;
    }

    /**
     * Tokenises "I just {want} to go." into ordered chunks.
     *
     * @return list<array{0: bool, 1: string}> [isBlank, chunk] pairs
     */
    private static function letterSpaceSplit(string $text): array
    {
        $pieces = preg_split('/\{([^{}]+)\}/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE);

        if ($pieces === false) {
            return [];
        }

        $out = [];

        foreach ($pieces as $i => $piece) {
            // preg_split alternates text / captured blank; empty text
            // runs (blank at the start or two adjacent blanks) are noise.
            $isBlank = $i % 2 === 1;

            if (!$isBlank && $piece === '') {
                continue;
            }

            $out[] = [$isBlank, $piece];
        }

        return $out;
    }

    /**
     * In `translation` pair mode the right side is bilingual, so it must
     * follow the student's interface language — a Russian speaker shown
     * the Turkmen column would be matching against words they cannot
     * read.
     *
     * @return list<string>
     */
    private static function rightSides(array $pairs, string $lang): array
    {
        $out = [];

        foreach ($pairs as $pair) {
            $localised = $lang === 'ru'
                ? ($pair['right_ru'] ?? $pair['right_tk'] ?? null)
                : ($pair['right_tk'] ?? $pair['right_ru'] ?? null);

            $out[] = (string) ($pair['right'] ?? $localised ?? '');
        }

        return $out;
    }

    private static function shuffled(array $items): array
    {
        $copy = array_values($items);
        shuffle($copy);

        return $copy;
    }

    /**
     * Shuffle that refuses to deal the solved order — a reorder puzzle
     * that arrives already in sequence is not a puzzle. With 3+ distinct
     * tokens a few retries always find a different permutation.
     */
    private static function scrambled(array $items): array
    {
        $original = array_values($items);

        if (count(array_unique($original)) < 2) {
            return $original;
        }

        $copy = $original;

        for ($i = 0; $i < 10 && $copy === $original; $i++) {
            shuffle($copy);
        }

        return $copy;
    }
}
