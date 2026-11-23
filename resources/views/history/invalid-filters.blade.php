<x-layouts.app title="Invalid History Filters">
    <h1 class="h3 mb-3">Invalid history filters</h1>

    <div class="alert alert-danger" role="alert">
        <p class="mb-2">The history could not be filtered:</p>
        <ul class="mb-0">
            @foreach ($messages as $message)
                <li>{{ $message }}</li>
            @endforeach
        </ul>
    </div>

    <a href="{{ $clearUrl }}" class="btn btn-outline-secondary">Clear filters</a>
</x-layouts.app>
