{{--
    One comparison panel. Expects: $result (App\Demonstrations\ModeResult), $columns (list<string>).
    Every value is escaped with {{ }}; input segments are wrapped in <span class="mark">.
--}}
@php
    $isVulnerable = $result->isVulnerable();
    $tone = $isVulnerable ? 'danger' : 'success';
    $statusBadge = match ($result->status) {
        App\Demonstrations\ModeResult::EXECUTED => ['text-bg-success', 'Executed'],
        App\Demonstrations\ModeResult::FAILED => ['text-bg-warning', 'Database error'],
        default => ['text-bg-secondary', 'Not executed'],
    };
@endphp
<section class="card h-100 border-{{ $tone }}" aria-labelledby="{{ $result->mode }}-heading">
    <div class="card-header bg-{{ $tone }}-subtle d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
            <h2 id="{{ $result->mode }}-heading" class="h5 mb-0 text-{{ $tone }}-emphasis">{{ $isVulnerable ? 'Vulnerable Mode' : 'Secure Mode' }}</h2>
            <span class="small text-body-secondary">{{ $isVulnerable ? 'Input concatenated into the SQL text' : 'Parameterized query with bound values' }}</span>
        </div>
        <span class="badge {{ $statusBadge[0] }}">{{ $statusBadge[1] }}</span>
    </div>
    <div class="card-body">
        <h3 class="h6">{{ $isVulnerable ? 'Generated SQL' : 'SQL statement' }}</h3>
        @if ($isVulnerable)
            <pre class="bg-body-tertiary border rounded p-2 small mb-1 text-wrap"><code>@foreach ($result->segments as $segment)<span @class(['mark px-0' => $segment['input']])>{{ $segment['text'] }}</span>@endforeach</code></pre>
            <p class="small text-body-secondary mb-2">The <span class="mark px-1">highlighted</span> text is your input, inserted directly into the SQL code.</p>
            @if ($result->executedSql)
                <details class="small mb-2">
                    <summary>Executed statement (safety wrapper)</summary>
                    <pre class="bg-body-tertiary border rounded p-2 mt-2 mb-1 text-wrap"><code>{{ $result->executedSql }}</code></pre>
                    <p class="text-body-secondary mb-0">
                        SQLiGuard runs the generated SQL inside this fixed wrapper on a fresh, read-only, single-statement
                        connection. It enforces the expected columns, returns at most {{ App\Demonstrations\VulnerableQueryExecutor::ROW_LIMIT }} rows
                        and shortens long values. It does not prevent the injection itself.
                    </p>
                </details>
            @endif
        @else
            <pre class="bg-body-tertiary border rounded p-2 small mb-1 text-wrap"><code>{{ $result->sql }}</code></pre>
            <p class="small text-body-secondary mb-2">Each <code>?</code> is a placeholder. The query structure is fixed before any value is supplied.</p>
        @endif

        <h3 class="h6 mt-3">Bound parameters</h3>
        @if ($isVulnerable)
            <p class="small mb-0">None. The input is part of the SQL text itself.</p>
        @elseif ($result->bindings === [])
            <p class="small mb-0">None.</p>
        @else
            <table class="table table-sm small mb-0">
                <thead><tr><th scope="col" class="w-25">Placeholder</th><th scope="col">Value (sent separately as data)</th></tr></thead>
                <tbody>
                    @foreach ($result->bindings as $index => $binding)
                        <tr>
                            <td>#{{ $index + 1 }}</td>
                            <td><code class="text-break">{{ is_bool($binding) ? ($binding ? 'true' : 'false') : $binding }}</code></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        <h3 class="h6 mt-3">Result</h3>
        @if ($result->executed())
            <p class="small fw-semibold mb-2">{{ $result->summary }}</p>
            @if ($result->rows === [])
                <p class="small text-body-secondary mb-0">No rows returned.</p>
            @else
                <div class="table-responsive">
                    <table class="table table-sm table-striped small mb-0">
                        <thead>
                            <tr>
                                @foreach ($columns as $column)
                                    <th scope="col">{{ $column }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($result->rows as $row)
                                <tr>
                                    @foreach ($columns as $column)
                                        <td class="text-break">{{ $row[$column] ?? 'NULL' }}</td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if ($result->truncated)
                    <p class="small text-body-secondary mt-1 mb-0">Only the first {{ App\Demonstrations\VulnerableQueryExecutor::ROW_LIMIT }} rows are shown.</p>
                @endif
            @endif
        @else
            <div class="alert alert-{{ $result->status === App\Demonstrations\ModeResult::REFUSED ? 'secondary' : 'warning' }} small mb-0" role="status">
                {{ $result->message }}
            </div>
        @endif
    </div>
</section>
