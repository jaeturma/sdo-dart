<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * App-wide switch for whether the 'verified' check blocks users without a
 * confirmed email. Since self-registration is disabled and admins create
 * accounts directly (with no verification email actually delivered in most
 * deployments — MAIL_MAILER=log), this can be turned off so those accounts
 * aren't locked out. Always exactly one row.
 */
class EmailVerificationSetting extends Model
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
