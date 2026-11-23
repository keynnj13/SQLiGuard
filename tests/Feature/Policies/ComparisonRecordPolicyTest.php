<?php

namespace Tests\Feature\Policies;

use App\Enums\Role;
use App\Models\ComparisonRecord;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ComparisonRecordPolicyTest extends TestCase
{
    private function user(Role $role, int $id): User
    {
        return User::factory()->make(['role' => $role])->forceFill(['id' => $id]);
    }

    private function recordOwnedBy(int $userId): ComparisonRecord
    {
        return ComparisonRecord::factory()->make(['user_id' => $userId]);
    }

    /**
     * @return array<string, array{Role, bool}>
     */
    public static function roles(): array
    {
        return [
            'administrator' => [Role::Administrator, true],
            'student' => [Role::Student, false],
        ];
    }

    #[DataProvider('roles')]
    public function test_both_roles_list_their_own_history_but_only_administrators_list_everyones(Role $role, bool $isAdministrator): void
    {
        $user = $this->user($role, 1);

        $this->assertTrue($user->can('viewAny', ComparisonRecord::class));
        $this->assertSame($isAdministrator, $user->can('viewAll', ComparisonRecord::class));
    }

    #[DataProvider('roles')]
    public function test_everyone_views_their_own_record_and_only_administrators_view_others(Role $role, bool $isAdministrator): void
    {
        $user = $this->user($role, 1);

        $this->assertTrue($user->can('view', $this->recordOwnedBy(1)));
        $this->assertSame($isAdministrator, $user->can('view', $this->recordOwnedBy(2)));
    }

    public function test_another_users_record_is_denied_as_not_found(): void
    {
        $response = Gate::forUser($this->user(Role::Student, 1))->inspect('view', $this->recordOwnedBy(2));

        $this->assertTrue($response->denied());
        $this->assertSame(404, $response->status());
    }

    /**
     * @return array<string, array{Role}>
     */
    public static function everyRole(): array
    {
        return [
            'administrator' => [Role::Administrator],
            'student' => [Role::Student],
        ];
    }

    #[DataProvider('everyRole')]
    public function test_nobody_can_update_or_delete_history(Role $role): void
    {
        $user = $this->user($role, 1);
        $ownRecord = $this->recordOwnedBy(1);

        $this->assertFalse($user->can('update', $ownRecord));
        $this->assertFalse($user->can('delete', $ownRecord));
        $this->assertFalse($user->can('update', $this->recordOwnedBy(2)));
        $this->assertFalse($user->can('delete', $this->recordOwnedBy(2)));
    }
}
