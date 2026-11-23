{{-- Comparison outcome badge from results_differ (bool|null). The text, not the colour, carries the meaning. --}}
@php
    [$outcomeLabel, $outcomeClass] = match ($resultsDiffer) {
        true => ['Different results', 'text-bg-danger'],
        false => ['Same results', 'text-bg-success'],
        default => ['Not compared', 'text-bg-secondary'],
    };
@endphp
<span class="badge {{ $outcomeClass }}" data-outcome="{{ $resultsDiffer === null ? 'not_compared' : ($resultsDiffer ? 'different' : 'same') }}">{{ $outcomeLabel }}</span>
