<?php

namespace App\Enums;

/**
 * The two application roles. The string values are stored in `users.role`
 * and used as `role:` middleware parameters; keep them in sync with the
 * check constraint in the add_role_and_is_active_to_users_table migration.
 */
enum Role: string
{
    case Administrator = 'administrator';
    case Student = 'student';

    /**
     * Human-readable name shown in the interface.
     */
    public function label(): string
    {
        return match ($this) {
            self::Administrator => 'Administrator',
            self::Student => 'Student/Tester',
        };
    }

    /**
     * Name of the route a user with this role lands on after logging in.
     */
    public function homeRoute(): string
    {
        return match ($this) {
            self::Administrator => 'admin.dashboard',
            self::Student => 'student.dashboard',
        };
    }
}
