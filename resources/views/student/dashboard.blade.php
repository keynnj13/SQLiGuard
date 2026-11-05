<x-layouts.app title="Student dashboard">
    <h1 class="h3 mb-1">Student/Tester dashboard</h1>
    <p class="text-body-secondary mb-4">Welcome, {{ auth()->user()->name }}.</p>

    <div class="card">
        <div class="card-body">
            <h2 class="h5 card-title">SQL injection demonstrations</h2>
            <p class="card-text">
                Compare Vulnerable Mode and Secure Mode side by side for tautology, UNION and comment-based injection.
            </p>
            <a href="{{ route('demonstrations.index') }}" class="btn btn-primary">Open demonstrations</a>
            <p class="card-text small text-body-secondary mt-3 mb-0">Your personal test history will be added in a later phase.</p>
        </div>
    </div>
</x-layouts.app>
