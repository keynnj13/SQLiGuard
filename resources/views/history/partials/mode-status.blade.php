{{-- Mode status badge ($status: executed, failed or refused). The text, not the colour, carries the meaning. --}}
@php
    [$statusLabel, $statusClass] = match ($status) {
        App\Demonstrations\ModeResult::EXECUTED => ['Executed', 'text-bg-success'],
        App\Demonstrations\ModeResult::FAILED => ['Failed', 'text-bg-danger'],
        default => ['Not run (refused)', 'text-bg-secondary'],
    };
@endphp
<span class="badge {{ $statusClass }}">{{ $statusLabel }}</span>
