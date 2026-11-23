{{--
    Payload classification panel. Expects:
    $classification (App\Demonstrations\Classification\Classification),
    $rules (Classification::displayRules(): allowlisted rules grouped by field),
    $altered (bool|null, Comparison::inputAlteredQuery()).
    The label is a heuristic shown NEXT TO the observed effect, never instead of it.
    Every value is escaped with {{ }}; nothing here comes from the submitted input.
--}}
@php
    $categoryBadge = match ($classification->category) {
        App\Enums\PayloadCategory::Normal => 'text-bg-secondary',
        App\Enums\PayloadCategory::Unknown => 'text-bg-warning',
        default => 'text-bg-danger',
    };
@endphp
<section class="card mb-3 border-info-subtle" aria-labelledby="classification-heading">
    <div class="card-header bg-info-subtle d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
            <h2 id="classification-heading" class="h6 mb-0">Payload classification</h2>
            <span class="small text-body-secondary">Rule-based label for the input, shown separately from what the queries actually did</span>
        </div>
        <span class="badge {{ $categoryBadge }} fs-6" data-classification="{{ $classification->category->value }}">{{ $classification->category->label() }}</span>
    </div>
    <div class="card-body small">
        <p class="text-body-secondary mb-2">
            Payload classification is an educational, rule-based aid. It does not detect every SQL injection technique and is not a security boundary. Parameterized queries provide the primary protection against SQL injection.
        </p>

        @if ($altered === true && ! $classification->isAttackPattern())
            <div class="alert alert-warning py-2 mb-2" role="status">
                The classifier did not recognize an attack pattern, but the vulnerable query was still altered. Pattern matching misses attacks; only parameterized queries prevent them.
            </div>
        @elseif ($altered === false && $classification->isAttackPattern())
            <div class="alert alert-info py-2 mb-2" role="status">
                The classifier recognized an attack-like pattern, but both modes returned the same records for this input. This does not necessarily mean the classification was incorrect; the query may have changed without affecting the returned results.
            </div>
        @endif

        @if ($rules === [])
            <p class="mb-0">No classification rule matched this input.</p>
        @else
            <p class="text-body-secondary mb-2">
                When several patterns match, the label follows the precedence UNION, then tautology, then comment. Every matched rule is listed below.
            </p>
            @foreach ($rules as $group)
                <h3 class="h6 mt-2 mb-1">{{ $group['label'] }}</h3>
                <ul class="mb-0 ps-3">
                    @foreach ($group['rules'] as $rule)
                        <li><code>{{ $rule['id'] }}</code> &middot; {{ $rule['explanation'] }}</li>
                    @endforeach
                </ul>
            @endforeach
        @endif

        <p class="text-body-secondary mt-2 mb-0">Classifier version {{ $classification->version }}</p>
    </div>
</section>
