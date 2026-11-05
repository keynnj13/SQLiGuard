<x-layouts.app title="Administrator dashboard">
    <h1 class="h3 mb-1">Administrator dashboard</h1>
    <p class="text-body-secondary mb-4">Welcome, {{ auth()->user()->name }}.</p>

    <div class="row g-4">
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-body">
                    <h2 class="h5 card-title">Accounts</h2>
                    <p class="card-text">Create Administrator and Student/Tester accounts, edit them, and activate or deactivate access.</p>
                    <a href="{{ route('admin.users.index') }}" class="btn btn-primary">Manage accounts</a>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-body">
                    <h2 class="h5 card-title">Summaries</h2>
                    <p class="card-text text-body-secondary mb-0">
                        Test records, Vulnerable Mode settings and dashboard summaries will be added in a later phase.
                    </p>
                </div>
            </div>
        </div>
    </div>
</x-layouts.app>
