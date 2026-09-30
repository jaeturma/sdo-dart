<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use Illuminate\Database\Eloquent\Model;

/**
 * App-wide switch for whether the ASDS review stage is currently staffed.
 * When marked unavailable, outgoing documents skip straight from division
 * chief review to SDS review so approvals aren't held up. Only affects future
 * transitions — a document already sitting at pending_asds_review is
 * unaffected by later toggles. Always exactly one row.
 */
class AsdsAvailabilitySetting extends Model
{
    protected $fillable = ['available'];

    protected $casts = ['available' => 'boolean'];

    public static function isAvailable(): bool
    {
        return static::query()->firstOrCreate([], ['available' => true])->available;
    }

    public static function toggle(): bool
    {
        $row = static::query()->firstOrCreate([], ['available' => true]);
        $row->update(['available' => ! $row->available]);

        return $row->available;
    }

    /**
     * Where a document goes after passing division-chief-level review —
     * normally ASDS, but straight to SDS when ASDS is marked unavailable.
     */
    public static function nextAfterDivisionChief(): DocumentStatus
    {
        return static::isAvailable() ? DocumentStatus::PendingAsdsReview : DocumentStatus::PendingSdsReview;
    }
}
