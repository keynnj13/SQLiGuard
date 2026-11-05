<x-layouts.app title="Access denied">
    <div class="row justify-content-center py-5">
        <div class="col-md-8 col-lg-6 text-center">
            <p class="display-6 fw-semibold text-danger mb-2">403</p>
            <h1 class="h4 mb-3">Access denied</h1>
            <p class="text-body-secondary mb-4">Your account does not have permission to view this page.</p>
            @auth
                <a href="{{ route('dashboard') }}" class="btn btn-primary">Go to your dashboard</a>
            @else
                <a href="{{ route('login') }}" class="btn btn-primary">Log in</a>
            @endauth
        </div>
    </div>
</x-layouts.app>
