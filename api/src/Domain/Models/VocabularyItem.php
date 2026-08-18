<?php

declare(strict_types=1);

namespace Dana\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** FR-6.1/6.2: authored by the superadmin, never generated. */
final class VocabularyItem extends Model
{
    protected $table = 'vocabulary_items';

    protected $fillable = [
        'section_id', 'term_en', 'part_of_speech', 'ipa',
        'translation_tk', 'translation_ru', 'example_en', 'audio_path', 'sort_order',
    ];

    protected $casts = ['sort_order' => 'integer'];

    /** The typed Vocabulary section this word belongs to (§13). */
    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class, 'section_id');
    }
}
