<x-layouts.app title="My Comparison History">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h1 class="h3 mb-0">My Comparison History</h1>
        <a href="{{ route('demonstrations.index') }}" class="btn btn-outline-primary btn-sm">Run a demonstration</a>
    </div>

    @include('history.partials.privacy')
    @include('history.partials.filters', ['action' => route('history.index'), 'filters' => $filters, 'showUserFilter' => false])
    @include('history.partials.table', ['records' => $records, 'filters' => $filters, 'showUser' => false])
</x-layouts.app>
