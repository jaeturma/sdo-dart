<?php

namespace App\Models;

use App\Enums\ReviewAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentReview extends Model
{
    // Append-only — no updated_at column
    const UPDATED_AT = null;

    protected $fillable = [
        'document_id',
        'reviewer_id',
        'role_at_review',
        'action',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'action'     => ReviewAction::class,
            'created_at' => 'datetime',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }
}
