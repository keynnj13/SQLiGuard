<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Administrator account management. Routes are in the `role:administrator`
 * group; every action is also authorized against UserPolicy.
 */
class UserController extends Controller
{
    /**
     * List all accounts.
     */
    public function index(): View
    {
        Gate::authorize('viewAny', User::class);

        return view('admin.users.index', [
            'users' => User::query()->orderBy('name')->orderBy('id')->paginate(15),
        ]);
    }

    /**
     * Show the account creation form.
     */
    public function create(): View
    {
        Gate::authorize('create', User::class);

        return view('admin.users.create', ['roles' => Role::cases()]);
    }

    /**
     * Create an active account with the chosen role.
     */
    public function store(StoreUserRequest $request): RedirectResponse
    {
        $user = new User;
        $user->forceFill([
            ...$request->safe()->only(['name', 'email', 'password']),
            'role' => $request->enum('role', Role::class),
            'is_active' => true,
        ])->save();

        return redirect()->route('admin.users.index')
            ->with('status', "Account created for {$user->email}.");
    }

    /**
     * Show the account edit form.
     */
    public function edit(Request $request, User $user): View
    {
        Gate::authorize('update', $user);

        return view('admin.users.edit', [
            'user' => $user,
            'roles' => Role::cases(),
            'isOwnAccount' => $user->is($request->user()),
        ]);
    }

    /**
     * Update name, email, role and (optionally) password.
     */
    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $actor = $request->user();

        DB::transaction(function () use ($request, $user, $actor): void {
            $role = $request->enum('role', Role::class) ?? $user->role;

            if ($role !== $user->role) {
                $this->ensureAnotherActiveAdministratorRemains($user, 'role');

                if ($user->is($actor)) {
                    throw ValidationException::withMessages(['role' => 'You cannot change your own role.']);
                }
            }

            $user->fill($request->safe()->only(['name', 'email']));
            $user->role = $role;

            if ($request->filled('password')) {
                $user->password = $request->validated('password');
            }

            $user->save();
        });

        // Keep the session's stored password hash (auth.session) in sync when
        // administrators change their own password, so they stay logged in.
        if ($user->is($actor)) {
            $actor->refresh();
        }

        return redirect()->route('admin.users.index')
            ->with('status', "Account updated for {$user->email}.");
    }

    /**
     * Allow the account to log in again.
     */
    public function activate(User $user): RedirectResponse
    {
        Gate::authorize('update', $user);

        $user->forceFill(['is_active' => true])->save();

        return back()->with('status', "Account activated for {$user->email}.");
    }

    /**
     * Block the account from logging in and end its open sessions on their next request.
     */
    public function deactivate(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('update', $user);

        DB::transaction(function () use ($request, $user): void {
            $this->ensureAnotherActiveAdministratorRemains($user, 'account');

            if ($user->is($request->user())) {
                throw ValidationException::withMessages(['account' => 'You cannot deactivate your own account.']);
            }

            $user->forceFill(['is_active' => false])->save();
        });

        return back()->with('status', "Account deactivated for {$user->email}.");
    }

    /**
     * Refuse a change that would leave no active administrator.
     *
     * Locks every active administrator row (including the target) in a fixed
     * order, so two concurrent requests cannot each remove a different last
     * administrator. Must be called inside a transaction.
     *
     * @throws ValidationException
     */
    private function ensureAnotherActiveAdministratorRemains(User $user, string $errorKey): void
    {
        $activeAdministratorIds = User::query()
            ->activeAdministrators()
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id');

        if ($activeAdministratorIds->contains($user->getKey()) && $activeAdministratorIds->count() === 1) {
            throw ValidationException::withMessages([
                $errorKey => 'At least one active administrator must remain. Activate or promote another administrator first.',
            ]);
        }
    }
}
