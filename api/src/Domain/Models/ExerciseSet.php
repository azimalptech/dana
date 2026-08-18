<?php

declare(strict_types=1);

namespace Dana\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ExerciseSet extends Model
{
    public const TYPE_REORDER           = 'reorder';
    public const TYPE_MATCH_PAIRS       = 'match_pairs';
    public const TYPE_MULTIPLE_CHOICE   = 'multiple_choice';
    public const TYPE_FILL_BLANK        = 'fill_blank';
    /** FR-13.21 — the fifth type, typed missing letters. */
    public const TYPE_FILL_LETTER_SPACE = 'fill_letter_space';

    public const STATUS_DRAFT     = 'draft';
    public const STATUS_IN_REVIEW = 'in_review';
    public const STATUS_PUBLISHED = 'published';

    /** FR-4.11 — match_pairs is the exception at 4–5. */
    public const MIN_QUESTIONS = 7;
    public const MAX_QUESTIONS = 12;
    public const MIN_PAIRS = 4;
    public const MAX_PAIRS = 5;

    protected $table = 'exercise_sets';

    protected $fillable = [
        'section_id', 'type', 'title_tk', 'title_ru',
        'instructions_tk', 'instructions_ru', 'input_mode', 'pair_mode',
        'status', 'sort_order',
    ];

    protected $casts = ['sort_order' => 'integer'];

    /** The typed section (§13) this set belongs to. */
    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class, 'section_id');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(Question::class)->where('is_active', 1)->orderBy('sort_order');
    }

    /** @return array{0: int, 1: int} */
    public function questionBounds(): array
    {
        return $this->type === self::TYPE_MATCH_PAIRS
            ? [self::MIN_PAIRS, self::MAX_PAIRS]
            : [self::MIN_QUESTIONS, self::MAX_QUESTIONS];
    }
}
