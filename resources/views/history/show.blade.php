<x-layouts.app title="Comparison Details">
    @php($recordedAt = $record->created_at->timezone(config('app.timezone')))

    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
            <h1 class="h3 mb-1">{{ $record->demonstration_type->demonstration()->title() }}</h1>
            <p class="text-body-secondary mb-0">
                Recorded <time datetime="{{ $record->created_at->toIso8601String() }}">{{ $recordedAt->format('Y-m-d H:i:s') }} ({{ config('app.timezone') }})</time>
            </p>
            @if ($showOwner)
                <p class="text-body-secondary mb-0" data-owner>
                    Run by {{ $record->user->name }} &lt;{{ $record->user->email }}&gt;
                    @unless ($record->user->is_active)
                        <span class="badge text-bg-danger ms-1">Inactive</span>
                    @endunless
                </p>
            @endif
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="{{ route('demonstrations.show', $record->demonstration_type) }}" class="btn btn-primary btn-sm">Run this demonstration again</a>
            <a href="{{ route('history.index') }}" class="btn btn-outline-secondary btn-sm">Back to My Comparison History</a>
            @can('viewAll', App\Models\ComparisonRecord::class)
                <a href="{{ route('admin.history.index') }}" class="btn btn-outline-secondary btn-sm">Back to All Comparison History</a>
            @endcan
        </div>
    </div>

    @include('history.partials.privacy')

    <section class="card mb-3" aria-labelledby="outcome-heading">
        <div class="card-body">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                <h2 id="outcome-heading" class="h5 mb-0">Comparison outcome</h2>
                @include('history.partials.outcome', ['resultsDiffer' => $record->results_differ])
            </div>
            <p class="mb-1">
                @if ($record->results_differ === true)
                    Vulnerable Mode failed or returned different records from Secure Mode for the same input.
                @elseif ($record->results_differ === false)
                    Both modes returned the same records for the same input.
                @else
                    The two modes could not be compared, because Vulnerable Mode did not run or Secure Mode did not complete.
                @endif
            </p>
            <p class="small text-body-secondary mb-0">
                This reflects the observed comparison outcome. It does not independently prove whether the SQL query structure changed.
            </p>
        </div>
    </section>

    <div class="row g-4 mb-3">
        <div class="col-lg-6">
            @include('history.partials.mode-card', ['record' => $record, 'prefix' => 'vulnerable', 'title' => 'Vulnerable Mode'])
        </div>
        <div class="col-lg-6">
            @include('history.partials.mode-card', ['record' => $record, 'prefix' => 'secure', 'title' => 'Secure Mode'])
        </div>
    </div>

    <p class="small text-body-secondary mb-4">
        Execution times are informational, not a direct performance comparison of parameterized and vulnerable queries. Vulnerable Mode includes the cost of opening a new database connection.
    </p>

    @if ($record->classifier_version !== App\Demonstrations\Classification\PayloadClassifier::VERSION)
        <div class="alert alert-info" role="status" data-classifier-version-note>
            This comparison was classified by classifier version {{ $record->classifier_version }}; the current version is {{ App\Demonstrations\Classification\PayloadClassifier::VERSION }}.
            Its stored rule IDs are explained with the current classifier's descriptions, and rule IDs the current classifier does not know are not shown.
        </div>
    @endif

    @include('demonstrations.partials.classification', ['classification' => $classification, 'rules' => $classificationRules, 'altered' => $record->results_differ])
</x-layouts.app>
