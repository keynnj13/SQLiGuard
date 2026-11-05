<x-layouts.app title="Accounts">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h1 class="h3 mb-0">Accounts</h1>
        @can('create', App\Models\User::class)
            <a href="{{ route('admin.users.create') }}" class="btn btn-primary">Create account</a>
        @endcan
    </div>

    @error('account')
        <div class="alert alert-danger" role="alert">{{ $message }}</div>
    @enderror

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th scope="col">Name</th>
                        <th scope="col">Email</th>
                        <th scope="col">Role</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($users as $user)
                        <tr>
                            <td>
                                {{ $user->name }}
                                @if ($user->is(auth()->user()))
                                    <span class="badge text-bg-light border ms-1">You</span>
                                @endif
                            </td>
                            <td>{{ $user->email }}</td>
                            <td>
                                <span class="badge {{ $user->isAdministrator() ? 'text-bg-primary' : 'text-bg-secondary' }}">
                                    {{ $user->role->label() }}
                                </span>
                            </td>
                            <td>
                                @if ($user->is_active)
                                    <span class="badge text-bg-success">Active</span>
                                @else
                                    <span class="badge text-bg-danger">Inactive</span>
                                @endif
                            </td>
                            <td class="text-end text-nowrap">
                                @can('update', $user)
                                    <a href="{{ route('admin.users.edit', $user) }}" class="btn btn-outline-secondary btn-sm">Edit</a>

                                    @if ($user->is_active)
                                        @unless ($user->is(auth()->user()))
                                            <form method="POST" action="{{ route('admin.users.deactivate', $user) }}" class="d-inline">
                                                @csrf
                                                @method('PATCH')
                                                <button type="submit" class="btn btn-outline-danger btn-sm">Deactivate</button>
                                            </form>
                                        @endunless
                                    @else
                                        <form method="POST" action="{{ route('admin.users.activate', $user) }}" class="d-inline">
                                            @csrf
                                            @method('PATCH')
                                            <button type="submit" class="btn btn-outline-success btn-sm">Activate</button>
                                        </form>
                                    @endif
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-body-secondary py-4">No accounts found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">
        {{ $users->links() }}
    </div>
</x-layouts.app>
