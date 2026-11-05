<?php

namespace App\Policies;

use App\Models\User;

/**
 * Authorization for account management. Only administrators manage accounts.
 *
 * Integrity rules that depend on application state (no self role change, no
 * self deactivation, at least one active administrator) are enforced inside
 * the controller's database transaction, where they can be checked atomically.
 */
class UserPolicy
{
    /**
     * Determine whether the user can view the account list.
     */
    public function viewAny(User $user): bool
    {
        return $user->isAdministrator();
    }

    /**
     * Determine whether the user can create accounts.
     */
    public function create(User $user): bool
    {
        return $user->isAdministrator();
    }

    /**
     * Determine whether the user can edit, activate or deactivate the account.
     */
    public function update(User $user, User $model): bool
    {
        return $user->isAdministrator();
    }
}
