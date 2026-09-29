<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SignatureAuditLog extends Model
{
    // Append-only — no updated_at column
    const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'document_id',
        'action',
        'ip_address',
        'user_agent',
        'metadata',
        'prev_hash',
        'row_hash',
    ];

    protected function casts(): array
    {
        return [
            'metadata'   => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
