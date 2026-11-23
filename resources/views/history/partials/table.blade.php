{{--
    History list. Expects $records (paginator), $filters (validated filters)
    and $showUser (administrator list: owner column, eager-loaded user).
--}}
<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <caption class="visually-hidden">Recorded comparisons, newest first</caption>
            <thead class="table-light">
                <tr>
                    <th scope="col">Date</th>
                    @if ($showUser)
                        <th scope="col">User</th>
                    @endif
                    <th scope="col">Demonstration</th>
                    <th scope="col">Classification</th>
                    <th scope="col">Vulnerable Mode</th>
                    <th scope="col">Secure Mode</th>
                    <th scope="col">Outcome</th>
                    <th scope="col" class="text-end">Details</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($records as $record)
                    <tr>
                        <td class="text-nowrap">
                            <time datetime="{{ $record->created_at->toIso8601String() }}">{{ $record->created_at->timezone(config('app.timezone'))->format('Y-m-d H:i') }}</time>
                        </td>
                        @if ($showUser)
                            <td>
                                {{ $record->user->name }}
                                @unless ($record->user->is_active)
                                    <span class="badge text-bg-danger ms-1">Inactive</span>
                                @endunless
                                <div class="small text-body-secondary text-break">{{ $record->user->email }}</div>
                            </td>
                        @endif
                        <td>{{ $record->demonstration_type->demonstration()->title() }}</td>
                        <td>{{ $record->classification_category->label() }}</td>
                        <td>@include('history.partials.mode-summary', ['record' => $record, 'prefix' => 'vulnerable'])</td>
                        <td>@include('history.partials.mode-summary', ['record' => $record, 'prefix' => 'secure'])</td>
                        <td>@include('history.partials.outcome', ['resultsDiffer' => $record->results_differ])</td>
                        <td class="text-end">
                            <a href="{{ route('history.show', $record) }}" class="btn btn-outline-secondary btn-sm">
                                View<span class="visually-hidden"> comparison {{ $record->id }}</span>
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ $showUser ? 8 : 7 }}" class="text-center text-body-secondary py-4">
                            {{ $filters === [] ? 'No comparisons have been recorded yet.' : 'No comparisons match these filters.' }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="mt-3">
    {{ $records->links() }}
</div>
