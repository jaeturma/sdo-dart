<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserSigningCredential extends Model
{
    protected $fillable = [
        'user_id',
        'p12_path',
        'p12_hash',
        'esig_path',
        'esig_hash',
        'certificate_subject',
        'certificate_serial',
        'certificate_valid_from',
        'certificate_valid_until',
    ];

    protected function casts(): array
    {
        return [
            'certificate_valid_from'  => 'datetime',
            'certificate_valid_until' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The signing credential belongs to the SDS; the secretary attaches it on
     * the SDS's behalf, so signing always uses this rather than the acting
     * user's own credential.
     */
    public static function forSds(): ?self
    {
        return User::role('sds')->first()?->signingCredential;
    }
}
