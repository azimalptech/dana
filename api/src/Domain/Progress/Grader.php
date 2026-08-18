<?php

declare(strict_types=1);

namespace Dana\Domain\Progress;

use Dana\Domain\Models\ExerciseSet;
use Dana\Domain\Models\Question;

/**
 * Decides whether a submitted answer is correct.
 *
 * NFR-5: this runs server-side, always. The client submits what the
 * student did, never what it was worth. A tampered client cannot inflate
 * a leaderboard because it never gets to state an outcome — check() is
 * advisory UI feedback and submit() re-grades everything (FR-13.5).
 */
final class Grader
{
    /**
     * $type is the EXERCISE SET's type. It is passed in rather than read
     * from the question because a quiz composes questions from several
     * sibling sections' sets (FR-13.4), so the caller already holds the
     * set row joined in.
     */
    public function isCorrect(string $type, Question $question, mixed $answer): bool
    {
        $payload = $question->payload ?? [];

        return match ($type) {
            ExerciseSet::TYPE_MULTIPLE_CHOICE   => $this->gradeMultipleChoice($payload, $answer),
            ExerciseSet::TYPE_FILL_BLANK        => $this->gradeFillBlank($payload, $answer),
            ExerciseSet::TYPE_REORDER           => $this->gradeReorder($payload, $answer),
            ExerciseSet::TYPE_MATCH_PAIRS       => $this->gradeMatchPairs($payload, $answer),
            ExerciseSet::TYPE_FILL_LETTER_SPACE => $this->gradeFillLetterSpace($payload, $answer),
            default                             => false,
        };
    }

    /**
     * FR-12.6 requires the options shuffled on every attempt, so the
     * client cannot submit a stored index — it submits the option TEXT,
     * which is order-independent. A numeric index is still accepted for
     * compatibility, interpreted against the stored (unshuffled) order.
     */
    private function gradeMultipleChoice(array $payload, mixed $answer): bool
    {
        $correctIndex = (int) ($payload['answer'] ?? -1);
        $options = $payload['options'] ?? [];
        $correct = $options[$correctIndex] ?? '';
        // v2 options are part-objects; the comparable text is the text
        // part (media parts have none, so a text submission cannot match
        // them — the client submits the index for those).
        $correctText = is_array($correct) ? (string) ($correct['text'] ?? '') : (string) $correct;

        // A STRING is always the option text — never a stored index. This
        // matters when the options themselves are digits ("1901", a
        // price): the old digit-string→index path graded the right answer
        // as an index and marked every attempt wrong. The app only ever
        // submits text; a bare int index stays accepted for any
        // programmatic caller.
        if (is_string($answer)) {
            return $correctText !== ''
                && $this->normalise($answer) === $this->normalise($correctText);
        }

        if (is_int($answer)) {
            return $answer === $correctIndex;
        }

        return false;
    }

    /**
     * FR-4.19: the student taps words from a bank, so the submission is
     * a word, not free text. Compared case- and whitespace-insensitively
     * anyway — a correct answer should not fail on presentation.
     */
    private function gradeFillBlank(array $payload, mixed $answer): bool
    {
        $expected = $payload['answer'] ?? [];
        $expected = is_array($expected) ? $expected : [$expected];

        $submitted = is_array($answer) ? $answer : [$answer];

        if (count($submitted) !== count($expected)) {
            return false;
        }

        foreach ($expected as $i => $word) {
            if ($this->normalise((string) $word) !== $this->normalise((string) ($submitted[$i] ?? ''))) {
                return false;
            }
        }

        return true;
    }

    /**
     * FR-13.21: one string of typed letters per blank, in blank order,
     * graded case-insensitively and trimmed. The expected letters come
     * from re-parsing the authored `{word}` marks — there is no separate
     * answer field that could drift out of sync with the text.
     */
    private function gradeFillLetterSpace(array $payload, mixed $answer): bool
    {
        $submitted = is_array($answer) ? array_values($answer) : [$answer];

        // v2 (§2): the mask's `<…>` groups are the hidden letters, one
        // typed string per group, compared case-insensitively.
        if (array_key_exists('mask', $payload)) {
            $blanks = Question::maskBlanks((string) $payload['mask']);

            if ($blanks === [] || count($submitted) !== count($blanks)) {
                return false;
            }

            foreach ($blanks as $i => $blank) {
                $typed = mb_strtolower(trim((string) ($submitted[$i] ?? '')));

                if ($typed !== mb_strtolower($blank)) {
                    return false;
                }
            }

            return true;
        }

        // FR-13.21 (legacy): letters missing from each `{word}`, derived
        // from the marked text and the reveal count.
        $blanks = Question::letterBlanks($payload);

        if ($blanks === [] || count($submitted) !== count($blanks)) {
            return false;
        }

        foreach ($blanks as $i => $blank) {
            $typed = mb_strtolower(trim((string) ($submitted[$i] ?? '')));

            if ($typed !== mb_strtolower($blank['missing'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * FR-12.5: words into one sentence, exactly one valid ordering.
     *
     * Compared as a reconstructed sentence rather than as index
     * positions, so a set containing a repeated word ("to ... to") is
     * still graded correctly when the student picks the other identical
     * token.
     */
    private function gradeReorder(array $payload, mixed $answer): bool
    {
        if (!is_array($answer)) {
            return false;
        }

        $tokens = $payload['tokens'] ?? [];

        if ($tokens === []) {
            return false;
        }

        // v2 payloads carry no `answer` array — token order IS the
        // answer (docs/06-CONTENT-V2.md §2), so the identity order is
        // derived. Legacy payloads keep their stored range.
        $order = $payload['answer'] ?? range(0, count($tokens) - 1);

        $expected = [];
        foreach ($order as $index) {
            $expected[] = (string) ($tokens[$index] ?? '');
        }

        $submitted = array_map(
            fn ($t): string => is_int($t) ? (string) ($tokens[$t] ?? '') : (string) $t,
            $answer
        );

        return $this->normalise(implode(' ', $expected)) === $this->normalise(implode(' ', $submitted));
    }

    /**
     * Answer shape: { left => right } for every pair. Order of the map
     * is irrelevant; every pair must be right.
     */
    private function gradeMatchPairs(array $payload, mixed $answer): bool
    {
        if (!is_array($answer)) {
            return false;
        }

        $pairs = $payload['pairs'] ?? [];

        if (count($answer) !== count($pairs)) {
            return false;
        }

        foreach ($pairs as $pair) {
            $left = $this->normalise((string) ($pair['left'] ?? ''));

            $submitted = null;
            foreach ($answer as $key => $value) {
                if ($this->normalise((string) $key) === $left) {
                    $submitted = $this->normalise((string) $value);
                    break;
                }
            }

            if ($submitted === null) {
                return false;
            }

            // In `translation` mode the right side is bilingual and the
            // student was shown whichever column matches their interface
            // language — so ANY of the stored variants is a correct
            // match. Comparing against only one language would mark a
            // Russian speaker's correct answer wrong.
            $variants = array_filter([
                $pair['right'] ?? null,
                $pair['right_tk'] ?? null,
                $pair['right_ru'] ?? null,
            ], static fn ($v): bool => $v !== null && $v !== '');

            $matched = false;
            foreach ($variants as $variant) {
                if ($this->normalise((string) $variant) === $submitted) {
                    $matched = true;
                    break;
                }
            }

            if (!$matched) {
                return false;
            }
        }

        return true;
    }

    private function normalise(string $value): string
    {
        $lower = mb_strtolower(trim($value));

        return (string) preg_replace('/\s+/u', ' ', $lower);
    }
}
