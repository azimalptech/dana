<?php

declare(strict_types=1);

namespace Dana\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A typed section under a child unit (FR-13.1/FR-13.2): grammar,
 * vocabulary, listening, or the child unit's Exam Quiz.
 *
 * This is the atomic student-facing unit of the §13 model: content
 * attaches here, attempts key here, and `status` is the ONLY visibility
 * gate — there is no unlocking anywhere (FR-13.3). Sections are
 * optional: a child unit shows exactly the sections the superadmin
 * added, no placeholders.
 *
 * At most one quiz per child unit is enforced in code, not the schema
 * (MariaDB 10.4 has no partial unique keys — see 008_redesign.sql).
 */
final class Section extends Model
{
    public const TYPE_GRAMMAR    = 'grammar';
    public const TYPE_VOCABULARY = 'vocabulary';
    public const TYPE_LISTENING  = 'listening';
    public const TYPE_QUIZ       = 'quiz';

    public const STATUS_DRAFT     = 'draft';
    public const STATUS_PUBLISHED = 'published';

    protected $table = 'sections';

    protected $fillable = [
        'unit_section_id', 'type', 'title_tk', 'title_ru', 'status', 'sort_order',
    ];

    protected $casts = ['sort_order' => 'integer'];

    /** The child unit (1A, 1B …) this section belongs to. */
    public function childUnit(): BelongsTo
    {
        return $this->belongsTo(UnitSection::class, 'unit_section_id');
    }

    public function exerciseSets(): HasMany
    {
        return $this->hasMany(ExerciseSet::class)->orderBy('sort_order');
    }

    public function vocabulary(): HasMany
    {
        return $this->hasMany(VocabularyItem::class)->orderBy('sort_order');
    }

    public function grammar(): HasOne
    {
        return $this->hasOne(GrammarExplanation::class);
    }
}
