<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Document extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'type',
        'title',
        'description',
        'uploaded_by',
        'division_id',
        'source_origin',
        'reference_number',
        'control_number',
        'file_path',
        'file_hash',
        'status',
        'requires_release',
    ];

    protected function casts(): array
    {
        return [
            'type' => DocumentType::class,
            'status' => DocumentStatus::class,
            'released_at' => 'datetime',
            'requires_release' => 'boolean',
            'emailed_at' => 'datetime',
            'files_purged_at' => 'datetime',
        ];
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function division(): BelongsTo
    {
        return $this->belongsTo(Division::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(DocumentReview::class);
    }

    public function routings(): HasMany
    {
        return $this->hasMany(DocumentRouting::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(SignatureAuditLog::class);
    }

    /**
     * A document can end up with more than one signature row — e.g. an admin
     * reverting status back to Approved after the secretary placed the RD's
     * signature in the wrong spot, so it can be signed again correctly.
     * Old rows are never deleted (append-only, matching the rest of this
     * app's audit trail), so this must explicitly pick the most recent one
     * rather than relying on hasOne()'s undefined "first match" ordering.
     */
    public function signature(): HasOne
    {
        return $this->hasOne(DocumentSignature::class)->latestOfMany('signed_at');
    }

    /**
     * Every signature ever applied to this document, not just the latest —
     * used by documents:purge-deleted-files to locate every signed_file_path
     * tied to a soft-deleted document, since a sign→undo→re-sign cycle
     * leaves each prior signed PDF on disk too.
     */
    public function signatures(): HasMany
    {
        return $this->hasMany(DocumentSignature::class);
    }

    public function releasedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by');
    }

    public function emailedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'emailed_by');
    }

    /**
     * Same reasoning as signature() above — a release stamp can likewise be
     * redone after a status revert, so always take the most recent row.
     */
    public function releaseStamp(): HasOne
    {
        return $this->hasOne(DocumentReleaseStamp::class)->latestOfMany('stamped_at');
    }

    /**
     * Every release stamp ever applied — same reasoning as signatures()
     * above.
     */
    public function releaseStamps(): HasMany
    {
        return $this->hasMany(DocumentReleaseStamp::class);
    }
}
