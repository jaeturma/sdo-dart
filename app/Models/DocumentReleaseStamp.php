<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentReleaseStamp extends Model
{
    protected $fillable = [
        'document_id',
        'stamped_by',
        'stamped_file_path',
        'stamped_file_hash',
        'certificate_subject',
        'certificate_serial',
        'stamp_page',
        'stamp_x_percent',
        'stamp_y_percent',
        'stamp_width_percent',
        'stamped_at',
    ];

    protected function casts(): array
    {
        return [
            'stamped_at' => 'datetime',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function stampedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'stamped_by');
    }
}
