<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * App-wide "force everyone to change their password" trigger — a single
 * timestamp compared against each user's own password_changed_at. Always
 * exactly one row. Used for suspected-compromise response, independent of
 * per-user must_change_password (which covers first-login-after-admin-creates
 * the account).
 */
class PasswordPolicySetting extends Model
{
    protected $fillable = ['force_reset_at'];

    protected $casts = ['force_reset_at' => 'datetime'];

    public static function forceResetAt(): ?Carbon
    {
        return static::query()->firstOrCreate([])->force_reset_at;
    }

    public static function forceResetAll(): void
    {
        static::query()->firstOrCreate([])->update(['force_reset_at' => now()]);
    }
}
