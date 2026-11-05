<?php

namespace Tests\Feature\Policies;

use App\Enums\Role;
use App\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UserPolicyTest extends TestCase
{
    /**
     * @return array<string, array{Role, bool}>
     */
    public static function roles(): array
    {
        return [
            'administrator is allowed' => [Role::Administrator, true],
            'student is denied' => [Role::Student, false],
        ];
    }

    #[DataProvider('roles')]
    public function test_account_management_abilities_follow_the_role(Role $role, bool $allowed): void
    {
        $actor = User::factory()->make(['role' => $role]);
        $target = User::factory()->make();

        $abilities = [
            'viewAny' => $actor->can('viewAny', User::class),
            'create' => $actor->can('create', User::class),
            'update' => $actor->can('update', $target),
        ];

        $this->assertSame(['viewAny' => $allowed, 'create' => $allowed, 'update' => $allowed], $abilities);
    }
}
