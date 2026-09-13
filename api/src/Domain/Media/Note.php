<?php

declare(strict_types=1);

namespace Dana\Domain\Media;

use Dana\Http\ApiException;

/**
 * Reads a v2 payload's `audio_note` / `image_note` and works out what
 * was actually asked for (FR-15.18).
 *
 * The notes are not one shape. In the client's own files they run from
 * a single word to a two-person scene:
 *
 *     audio_note  "seven"
 *     audio_note  "Receptionist: Good evening. How can I help you?
 *                  Guest: I have a reservation."
 *     image_note  "IMG_PEN"
 *     image_note  "FLAG_TURKEY"
 *     image_note  "italy"
 *
 * Getting this wrong is not a cosmetic problem. One voice reading
 * "Receptionist: Good evening" aloud, including the word Receptionist,
 * is a broken listening exercise. And FLAG_TURKEY with the prefix
 * naively stripped is "turkey" — which draws the bird, not the flag, in
 * a question whose other three options are countries.
 */
final class Note
{
    /** A label at the start of a line: "Guest:", "A:", "Receptionist:". */
    private const SPEAKER_LINE = '/^\s*([\p{L}][\p{L}\p{N} .\'\-]{0,23}):\s*(\S.*)$/u';

    /**
     * FLAG_TURKEY, IMG_PEN, IMG_DICTIONARY — an identifier, not prose.
     *
     * A trailing separator counts: "IMG_" is a malformed identifier, not
     * a subject, and it has to reach the empty check below rather than
     * fall through and be drawn literally. Prose can never match — it
     * has spaces or lower case.
     */
    private const IDENTIFIER = '/^[A-Z0-9][A-Z0-9_\-]*$/';

    /**
     * What to draw.
     *
     * An identifier is decoded; anything else is the author writing
     * English and is passed through exactly as typed. That distinction
     * is why "A - L - I." (a receptionist spelling out a name) keeps its
     * hyphens while IMG_COFFEE_CUP loses its underscores.
     */
    public static function imageSubject(string $note): string
    {
        $text = trim($note);

        if ($text === '') {
            throw self::empty();
        }

        if (!preg_match(self::IDENTIFIER, $text)) {
            return $text;
        }

        $parts = preg_split('/[_\-]+/', $text) ?: [];
        $head = strtoupper((string) array_shift($parts));
        $rest = trim(strtolower(implode(' ', $parts)));

        // FLAG_ is meaning, not noise: the subject is the flag OF the
        // country, and dropping the prefix would ask for the country's
        // namesake instead.
        if ($head === 'FLAG') {
            if ($rest === '') {
                throw self::empty();
            }

            return 'the national flag of ' . ucwords($rest);
        }

        if (in_array($head, ['IMG', 'IMAGE', 'PIC', 'PICTURE', 'PHOTO'], true)) {
            if ($rest === '') {
                throw self::empty();
            }

            return $rest;
        }

        // An identifier with no known prefix is its own subject:
        // "COFFEE_CUP" is a coffee cup.
        return trim(strtolower(str_replace(['_', '-'], ' ', $text)));
    }

    /**
     * What to say, and by whom.
     *
     * @return array{speakers: list<string>, lines: list<array{speaker: string, text: string}>, text: string}
     *         `speakers` empty means one unnamed voice reads `text`.
     */
    public static function speech(string $note): array
    {
        $text = trim($note);

        if ($text === '') {
            throw self::empty();
        }

        $lines = array_values(array_filter(
            array_map('trim', preg_split('/\R/u', $text) ?: []),
            static fn (string $line): bool => $line !== ''
        ));

        // A SINGLE line is always plain text, whatever punctuation it
        // holds. Otherwise a note like "Time: half past four" would be
        // read as a speaker called Time saying "half past four", and the
        // word the student is meant to hear would go missing.
        if (count($lines) < 2) {
            return ['speakers' => [], 'lines' => [], 'text' => self::flatten($text)];
        }

        $parsed = [];

        foreach ($lines as $line) {
            if (!preg_match(self::SPEAKER_LINE, $line, $m)) {
                // One unlabelled line means this is prose that happens to
                // wrap, not a script.
                return ['speakers' => [], 'lines' => [], 'text' => self::flatten($text)];
            }

            $parsed[] = ['speaker' => trim($m[1]), 'text' => trim($m[2])];
        }

        $speakers = [];

        foreach ($parsed as $line) {
            if (!in_array($line['speaker'], $speakers, true)) {
                $speakers[] = $line['speaker'];
            }
        }

        if (count($speakers) === 1) {
            // Labelled, but only one person — read it as one voice with
            // the label dropped.
            return [
                'speakers' => [],
                'lines'    => [],
                'text'     => self::flatten(implode(' ', array_column($parsed, 'text'))),
            ];
        }

        if (count($speakers) > 2) {
            throw new ApiException(
                'too_many_speakers',
                'Iki sesden köp goldanylmaýar.',
                'В диалоге больше двух говорящих (' . implode(', ', $speakers)
                . '), а Gemini озвучивает максимум двоих. Запишите этот файл вручную.',
                400
            );
        }

        // Voice is assigned by NAME, not by who speaks first. The same
        // pair appears in both orders across the course — "Teacher:
        // Open your books / Student: ..." in one unit and "Student:
        // Sorry I'm late / Teacher: ..." in another — and assigning by
        // order of appearance would give the teacher a different voice
        // in each. Sorting the two names fixes each role to one voice
        // for as long as the content lives.
        sort($speakers, SORT_NATURAL | SORT_FLAG_CASE);

        return ['speakers' => $speakers, 'lines' => $parsed, 'text' => ''];
    }

    /** Newlines and runs of space collapse; the words are untouched. */
    private static function flatten(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private static function empty(): ApiException
    {
        return new ApiException(
            'note_empty',
            'Bu bölekde ýazgy ýok.',
            'У этой части нет текста для озвучки или картинки.',
            400
        );
    }
}
