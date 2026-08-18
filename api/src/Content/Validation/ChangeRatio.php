<?php

declare(strict_types=1);

namespace Dana\Content\Validation;

/**
 * Gate G2 — the 20–25% rule (FR-4.4).
 *
 * The client asked for exercises built by changing each textbook
 * sentence by 20–25%, on the reasoning that generating from scratch
 * invites hallucination and level drift. This class is what turns that
 * instruction into something measurable, so a question can be rejected
 * on evidence rather than on the model's word.
 *
 * Both the generator prompt and the validator use this definition, so
 * they cannot disagree about what "25% changed" means.
 */
final class ChangeRatio
{
    /** Target band from the brief. */
    public const TARGET_MIN = 0.20;
    public const TARGET_MAX = 0.25;

    /**
     * Accepted band. Wider than the target on purpose: a 6-token
     * sentence moves in steps of 1/6 = 0.167, so no output could ever
     * land inside a strict 0.20–0.25 window. Rejecting valid short
     * sentences for arithmetic reasons would be a bug, not rigour.
     */
    public const ACCEPT_MIN = 0.15;
    public const ACCEPT_MAX = 0.30;

    /**
     * Token-level edit distance over max length. 0.0 = identical,
     * 1.0 = nothing in common.
     */
    public static function measure(string $source, string $output): float
    {
        $a = self::tokenize($source);
        $b = self::tokenize($output);

        $longest = max(count($a), count($b));

        if ($longest === 0) {
            return 0.0;
        }

        return round(self::distance($a, $b) / $longest, 3);
    }

    public static function isAccepted(float $ratio): bool
    {
        return $ratio >= self::ACCEPT_MIN && $ratio <= self::ACCEPT_MAX;
    }

    public static function isOnTarget(float $ratio): bool
    {
        return $ratio >= self::TARGET_MIN && $ratio <= self::TARGET_MAX;
    }

    /** @return list<string> */
    public static function tokenize(string $text): array
    {
        $normalised = mb_strtolower(trim($text));
        // Keep word characters and internal apostrophes ("don't" is one token).
        $normalised = preg_replace("/[^\\p{L}\\p{N}'\\s]/u", ' ', $normalised) ?? '';

        return array_values(array_filter(
            preg_split('/\s+/u', $normalised) ?: [],
            static fn (string $t): bool => $t !== '' && $t !== "'"
        ));
    }

    /**
     * Levenshtein over token arrays rather than characters — changing
     * "school" to "work" is one edit, not six.
     *
     * @param list<string> $a
     * @param list<string> $b
     */
    private static function distance(array $a, array $b): int
    {
        $lenA = count($a);
        $lenB = count($b);

        if ($lenA === 0) {
            return $lenB;
        }

        if ($lenB === 0) {
            return $lenA;
        }

        $previous = range(0, $lenB);
        $current = array_fill(0, $lenB + 1, 0);

        for ($i = 1; $i <= $lenA; $i++) {
            $current[0] = $i;

            for ($j = 1; $j <= $lenB; $j++) {
                $cost = $a[$i - 1] === $b[$j - 1] ? 0 : 1;
                $current[$j] = min(
                    $previous[$j] + 1,        // deletion
                    $current[$j - 1] + 1,     // insertion
                    $previous[$j - 1] + $cost // substitution
                );
            }

            $previous = $current;
        }

        return $previous[$lenB];
    }
}
