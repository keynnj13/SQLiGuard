{{--
    Shared account fields for the create and edit forms.
    Expects: $user (User|null), $roles (array<Role>), $isOwnAccount (bool).
    The role select is not rendered for an administrator's own account, so it
    is not submitted; the controller also rejects self role changes.
--}}
<div class="mb-3">
    <label for="name" class="form-label">Name</label>
    <input type="text" id="name" name="name" value="{{ old('name', $user?->name) }}"
           class="form-control @error('name') is-invalid @enderror" required maxlength="255" autocomplete="off">
    @error('name')
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
</div>

<div class="mb-3">
    <label for="email" class="form-label">Email address</label>
    <input type="email" id="email" name="email" value="{{ old('email', $user?->email) }}"
           class="form-control @error('email') is-invalid @enderror" required maxlength="255" autocomplete="off">
    @error('email')
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
</div>

<div class="mb-3">
    <label for="role" class="form-label">Role</label>
    @if ($isOwnAccount)
        <input type="text" id="role" class="form-control" value="{{ $user->role->label() }}" disabled>
        <div class="form-text">You cannot change your own role.</div>
    @else
        <select id="role" name="role" class="form-select @error('role') is-invalid @enderror" required>
            @foreach ($roles as $role)
                <option value="{{ $role->value }}" @selected(old('role', $user?->role->value ?? App\Enums\Role::Student->value) === $role->value)>
                    {{ $role->label() }}
                </option>
            @endforeach
        </select>
    @endif
    @error('role')
        <div class="invalid-feedback d-block">{{ $message }}</div>
    @enderror
</div>

<fieldset class="border rounded p-3 mb-4">
    <legend class="float-none w-auto px-2 fs-6 mb-0">{{ $user ? 'Change password (optional)' : 'Password' }}</legend>
    <p class="form-text mt-0">
        At least 12 characters, including upper- and lowercase letters, a number and a symbol.
        @if ($user)
            Leave blank to keep the current password.
        @endif
    </p>

    <div class="mb-3">
        <label for="password" class="form-label">{{ $user ? 'New password' : 'Password' }}</label>
        <input type="password" id="password" name="password"
               class="form-control @error('password') is-invalid @enderror"
               @unless ($user) required @endunless autocomplete="new-password">
        @error('password')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    <div>
        <label for="password_confirmation" class="form-label">Confirm password</label>
        <input type="password" id="password_confirmation" name="password_confirmation" class="form-control"
               @unless ($user) required @endunless autocomplete="new-password">
    </div>
</fieldset>
