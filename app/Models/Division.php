<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Division extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'code', 'parent_id', 'allow_sds_direct_routing', 'is_records_section'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'allow_sds_direct_routing' => 'boolean',
            'is_records_section' => 'boolean',
        ];
    }

    /**
     * The Office of the SDS itself — the division coded 'OSDS'. Many
     * behaviors key off that exact code (see isOsdsSecretary() and its
     * duplicates), so Office Management refuses to change it or delete
     * this office.
     */
    public function isOsds(): bool
    {
        return $this->code === 'OSDS';
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Division::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Division::class, 'parent_id')->orderBy('name');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    /**
     * Incoming routing action slips assigned to this whole division (a
     * shared assignment any member can acknowledge/complete) rather than
     * one specific user — see DocumentRoutingRecipient.division_id in
     * CLAUDE.md's "Incoming" workflow notes. Used by
     * DivisionController::deletionBlockers() to stop a division with real
     * routing history from being deleted.
     */
    public function routingRecipients(): HasMany
    {
        return $this->hasMany(DocumentRoutingRecipient::class);
    }

    /**
     * A division's own id plus every direct child division's id — e.g. for
     * scoping "a division chief's staff" to their division and its sub-units.
     *
     * @return array<int>
     */
    public static function selfAndChildrenIds(?int $divisionId): array
    {
        if (! $divisionId) {
            return [];
        }

        return collect([$divisionId])
            ->merge(static::where('parent_id', $divisionId)->pluck('id'))
            ->all();
    }

    /**
     * The Records Section's id — whichever office an admin designated via
     * Office Management (is_records_section). Null until one is designated,
     * which leaves release stamping, the Incoming Log, and the secretary's
     * auto-forward unavailable. DivisionController keeps the flag on at most
     * one office; the orderBy only makes the result deterministic if that
     * were ever bypassed.
     */
    public static function recordsSectionId(): ?int
    {
        return static::where('is_records_section', true)->orderBy('id')->value('id');
    }
}
