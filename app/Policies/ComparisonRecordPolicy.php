<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\ComparisonRecord;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Authorization for demonstration history. Read-only: history records are
 * never updated or deleted through the application.
 *
 * Everyone sees their own history; administrators also see everyone's.
 * Another user's record is reported as missing (404), not forbidden, so its
 * existence is not revealed.
 */
class ComparisonRecordPolicy
{
    /**
     * Determine whether the user can list their own history.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasRole(Role::Administrator, Role::Student);
    }

    /**
     * Determine whether the user can list every user's history.
     */
    public function viewAll(User $user): bool
    {
        return $user->isAdministrator();
    }

    /**
     * Determine whether the user can view the record: administrators any
     * record, everyone else only their own (otherwise 404).
     */
    public function view(User $user, ComparisonRecord $comparisonRecord): Response
    {
        return $user->isAdministrator() || $comparisonRecord->user_id === $user->getKey()
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    /**
     * History records are immutable.
     */
    public function update(User $user, ComparisonRecord $comparisonRecord): bool
    {
        return false;
    }

    /**
     * History records are never deleted through the application.
     */
    public function delete(User $user, ComparisonRecord $comparisonRecord): bool
    {
        return false;
    }
}
