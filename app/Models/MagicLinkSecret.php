<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The HMAC secret used to sign/verify RD/ARD magic login links — deliberately
 * separate from APP_KEY, so a leaked link can be revoked by rotating just
 * this value without invalidating sessions, encrypted cookies, or anything
 * else app-wide that relies on APP_KEY. Always exactly one row.
 */
class MagicLinkSecret extends Model
{
    protected $fillable = ['secret'];

    public static function current(): string
    {
        return static::query()->firstOrCreate([], ['secret' => Str::random(64)])->secret;
    }

    public static function rotate(): void
    {
        $row = static::query()->first();

        if ($row) {
            $row->update(['secret' => Str::random(64)]);
        } else {
            static::create(['secret' => Str::random(64)]);
        }
    }

    public static function lastRotatedAt(): ?Carbon
    {
        return static::query()->first()?->updated_at;
    }
}
