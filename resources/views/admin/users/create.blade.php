<x-layouts.app title="Create account">
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <h1 class="h3 mb-3">Create account</h1>

            <div class="card">
                <div class="card-body p-4">
                    <form method="POST" action="{{ route('admin.users.store') }}" novalidate>
                        @csrf

                        @include('admin.users.partials.form', ['user' => null, 'roles' => $roles, 'isOwnAccount' => false])

                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary">Create account</button>
                            <a href="{{ route('admin.users.index') }}" class="btn btn-outline-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</x-layouts.app>
