<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * App-wide switch for whether every Staff / Originator may create outgoing
 * documents, or only those also tagged KMT Focal (the default). See
 * DocumentPolicy::createOutgoing(). Toggling it never touches anyone's
 * kmt_focal role — switching it back off simply restores the KMT-only
 * rule. Always exactly one row.
 */
class OriginatorUploadSetting extends Model
{
    protected $fillable = ['all_originators'];

    protected $casts = ['all_originators' => 'boolean'];

    public static function allowsAllOriginators(): bool
    {
        return static::query()->firstOrCreate([], ['all_originators' => false])->all_originators;
    }

    public static function toggle(): bool
    {
        $row = static::query()->firstOrCreate([], ['all_originators' => false]);
        $row->update(['all_originators' => ! $row->all_originators]);

        return $row->all_originators;
    }
}
