{{-- One mode's outcome in a list row. Expects $record and $prefix ('vulnerable' or 'secure'). --}}
@php
    $status = $record->{$prefix.'_status'};
    $rowCount = $record->{$prefix.'_row_count'};
    $errorCategory = $record->{$prefix.'_error_category'};
@endphp
@include('history.partials.mode-status', ['status' => $status])
<div class="small text-body-secondary">
    @if ($rowCount !== null)
        {{ $rowCount }} {{ $rowCount === 1 ? 'row' : 'rows' }}
        @if ($prefix === 'vulnerable' && $record->vulnerable_truncated)
            <span class="d-block">Display limit reached</span>
        @endif
    @elseif ($errorCategory)
        {{ ucfirst(str_replace('_', ' ', $errorCategory->value)) }}
    @else
        No rows (not run)
    @endif
</div>
