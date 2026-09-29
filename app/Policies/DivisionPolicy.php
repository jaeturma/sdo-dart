<?php

namespace App\Policies;

use App\Models\Division;
use App\Models\User;

class DivisionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('admin');
    }

    public function create(User $user): bool
    {
        return $user->hasRole('admin');
    }

    public function update(User $user, Division $division): bool
    {
        return $user->hasRole('admin');
    }

    public function delete(User $user, Division $division): bool
    {
        return $user->hasRole('admin');
    }
}
