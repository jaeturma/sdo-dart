<?php

namespace App\Policies;

use App\Models\Division;
use App\Models\User;
use App\Models\UserSigningCredential;

class SigningCredentialPolicy
{
    /**
     * Admins bypass every policy check.
     */
    public function before(User $user, string $ability): ?bool
    {
        if ($user->hasRole('admin')) {
            return true;
        }

        return null;
    }

    /**
     * Can the user view a signing credential?
     * Each user may only view their own.
     */
    public function view(User $user, UserSigningCredential $credential): bool
    {
        return $user->id === $credential->user_id;
    }

    /**
     * Can the user upload their own signing credential?
     * Reviewing roles need one to approve/sign outgoing documents; Records
     * Section staff need one too, to attach their PNPKI-backed release stamp
     * when marking a document released. Admin bypass handled by before().
     */
    public function upload(User $user): bool
    {
        return $user->hasAnyRole(['rd', 'ard', 'division_chief'])
            || ($user->division_id !== null && $user->division_id === Division::recordsSectionId());
    }

    /**
     * Can the user delete/revoke a signing credential?
     * Each user may only delete their own; admin may delete any.
     */
    public function delete(User $user, UserSigningCredential $credential): bool
    {
        return $user->id === $credential->user_id;
    }
}
