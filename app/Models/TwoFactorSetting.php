<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * App-wide switch for whether EnsureTwoFactorEnabled forces every user
 * through 2FA setup. Always exactly one row. Toggling this off does not
 * touch any user's existing 2FA secret — it only stops the middleware
 * from requiring it.
 */
class TwoFactorSetting extends Model
{
    protected $fillable = ['enforced'];

    protected $casts = ['enforced' => 'boolean'];

    public static function isEnforced(): bool
    {
        return static::query()->firstOrCreate([], ['enforced' => true])->enforced;
    }

    public static function toggle(): bool
    {
        $row = static::query()->firstOrCreate([], ['enforced' => true]);
        $row->update(['enforced' => ! $row->enforced]);

        return $row->enforced;
    }
}
