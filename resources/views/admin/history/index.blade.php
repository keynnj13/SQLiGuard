<x-layouts.app title="All Comparison History">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h1 class="h3 mb-0">All Comparison History</h1>
        <a href="{{ route('history.index') }}" class="btn btn-outline-secondary btn-sm">My Comparison History</a>
    </div>

    @include('history.partials.privacy')
    @include('history.partials.filters', ['action' => route('admin.history.index'), 'filters' => $filters, 'showUserFilter' => true])
    @include('history.partials.table', ['records' => $records, 'filters' => $filters, 'showUser' => true])
</x-layouts.app>
