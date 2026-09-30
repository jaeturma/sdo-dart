<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DocumentSignature extends Model
{
    protected $fillable = [
        'document_id',
        'signed_by',
        'signed_file_path',
        'signed_file_hash',
        'certificate_subject',
        'certificate_serial',
        'signature_page',
        'sig_x_percent',
        'sig_y_percent',
        'sig_width_percent',
        'signed_at',
        'include_signature_details',
    ];

    protected function casts(): array
    {
        return [
            'signed_at' => 'datetime',
            'include_signature_details' => 'boolean',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function signer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'signed_by');
    }

    /**
     * One or more visual placements of the SDS's signature across the
     * document — see DocumentSignaturePlacement. `signature_page`/
     * `sig_x_percent`/`sig_y_percent`/`sig_width_percent` on this row still
     * mirror the *first* placement for backward compatibility with anything
     * reading those columns directly, but this relation is the source of
     * truth going forward.
     */
    public function placements(): HasMany
    {
        return $this->hasMany(DocumentSignaturePlacement::class);
    }
}
