<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\Role;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Application account, stored in `public.users` on the `pgsql` connection.
 *
 * `role` and `is_active` are intentionally not mass assignable: only the
 * administrator account-management actions and the create-admin command set them.
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Defaults mirrored from the migration so unsaved models observe them.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'role' => 'student',
        'is_active' => true,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => Role::class,
            'is_active' => 'boolean',
        ];
    }

    public function hasRole(Role ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    public function isAdministrator(): bool
    {
        return $this->hasRole(Role::Administrator);
    }

    /**
     * Limit the query to administrators whose accounts are active.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function activeAdministrators(Builder $query): void
    {
        $query->where('role', Role::Administrator)->where('is_active', true);
    }
}
