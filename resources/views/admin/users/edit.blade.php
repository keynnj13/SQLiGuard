<x-layouts.app title="Edit account">
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                <h1 class="h3 mb-0">Edit account</h1>
                @if ($user->is_active)
                    <span class="badge text-bg-success">Active</span>
                @else
                    <span class="badge text-bg-danger">Inactive</span>
                @endif
            </div>

            <div class="card">
                <div class="card-body p-4">
                    <form method="POST" action="{{ route('admin.users.update', $user) }}" novalidate>
                        @csrf
                        @method('PUT')

                        @include('admin.users.partials.form', ['user' => $user, 'roles' => $roles, 'isOwnAccount' => $isOwnAccount])

                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary">Save changes</button>
                            <a href="{{ route('admin.users.index') }}" class="btn btn-outline-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>

            <p class="form-text mt-3">Activate or deactivate this account from the account list.</p>
        </div>
    </div>
</x-layouts.app>
