<?php

declare(strict_types=1);

namespace Dana\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * FR-5: one explanation per section, both languages side by side. The
 * app renders whichever matches the interface language.
 *
 * §13: the explanation hangs off a typed Grammar section and has no
 * status of its own any more — the SECTION's draft/published state is
 * the visibility gate (008_redesign.sql).
 */
final class GrammarExplanation extends Model
{
    protected $table = 'grammar_explanations';

    protected $fillable = [
        'section_id', 'title_tk', 'title_ru', 'body_tk', 'body_ru', 'examples',
    ];

    protected $casts = ['examples' => 'array'];

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class, 'section_id');
    }
}
