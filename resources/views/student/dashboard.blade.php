<x-layouts.app title="Student dashboard">
    <h1 class="h3 mb-1">Student/Tester dashboard</h1>
    <p class="text-body-secondary mb-4">Welcome, {{ auth()->user()->name }}.</p>

    <div class="card">
        <div class="card-body">
            <h2 class="h5 card-title">SQL injection demonstrations</h2>
            <p class="card-text text-body-secondary mb-0">
                The side-by-side Vulnerable Mode and Secure Mode comparison and your personal test history will be
                available here in a later phase.
            </p>
        </div>
    </div>
</x-layouts.app>
