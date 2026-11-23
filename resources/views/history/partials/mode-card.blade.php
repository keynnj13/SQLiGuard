{{--
    One mode's stored outcome on the detail page. Expects $record, $prefix
    ('vulnerable' or 'secure') and $title. Metadata only: no SQL, input,
    parameters, rows or raw database errors exist to be shown.
--}}
@php
    $status = $record->{$prefix.'_status'};
    $rowCount = $record->{$prefix.'_row_count'};
    $errorCategory = $record->{$prefix.'_error_category'};
    $duration = $record->{$prefix.'_duration_us'};
    $truncated = $prefix === 'vulnerable' && $record->vulnerable_truncated;
@endphp
<section class="card h-100" aria-labelledby="{{ $prefix }}-mode-heading">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <h2 id="{{ $prefix }}-mode-heading" class="h5 mb-0">{{ $title }}</h2>
        @include('history.partials.mode-status', ['status' => $status])
    </div>
    <div class="card-body">
        <dl class="row small mb-0">
            <dt class="col-sm-5">Rows returned</dt>
            <dd class="col-sm-7">
                @if ($rowCount !== null)
                    {{ $rowCount }} {{ $rowCount === 1 ? 'row' : 'rows' }}
                    @if ($truncated)
                        <span class="d-block text-warning-emphasis">
                            More rows were returned than the display limit. Only the first {{ $rowCount }} were kept for display, so this is not the total number of matching records.
                        </span>
                    @endif
                @elseif ($status === App\Demonstrations\ModeResult::REFUSED)
                    Not available (not run)
                @else
                    Not available (the query did not complete)
                @endif
            </dd>

            <dt class="col-sm-5">Error category</dt>
            <dd class="col-sm-7">
                @if ($errorCategory)
                    {{ ucfirst(str_replace('_', ' ', $errorCategory->value)) }}
                    <span class="d-block text-body-secondary">{{ $errorCategory->safeMessage() }}</span>
                @else
                    None
                @endif
            </dd>

            <dt class="col-sm-5">Duration</dt>
            <dd class="col-sm-7 mb-0">
                @if ($duration !== null)
                    {{ number_format($duration) }} µs
                @else
                    Not measured (not run)
                @endif
            </dd>
        </dl>
    </div>
</section>
