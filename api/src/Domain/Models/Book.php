<?php

declare(strict_types=1);

namespace Dana\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Book extends Model
{
    public const KIND_STUDENTS_BOOK = 'students_book';
    public const KIND_WORKBOOK      = 'workbook';

    protected $table = 'books';

    protected $fillable = [
        'book_set_id', 'level_id', 'kind', 'title', 'edition',
        'file_path', 'page_count', 'text_status',
    ];

    protected $casts = ['page_count' => 'integer'];

    /**
     * The PDF path is an internal filesystem location outside the web
     * root — it must never reach a client response.
     */
    protected $hidden = ['file_path'];

    public function bookSet(): BelongsTo
    {
        return $this->belongsTo(BookSet::class);
    }
}
