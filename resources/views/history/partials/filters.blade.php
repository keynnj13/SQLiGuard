{{--
    GET filter form. Expects $action (list URL), $filters (validated filters)
    and $showUserFilter (administrator list only).
--}}
<form method="GET" action="{{ $action }}" class="card mb-3" role="search" aria-label="Filter comparison history">
    <div class="card-body">
        <div class="row g-2 align-items-end">
            @if ($showUserFilter)
                <div class="col-sm-6 col-lg-4">
                    <label for="filter-user" class="form-label small mb-1">User name or email</label>
                    <input type="search" id="filter-user" name="user" value="{{ $filters['user'] ?? '' }}" maxlength="100"
                           class="form-control form-control-sm" autocomplete="off">
                </div>
            @endif
            <div class="col-sm-6 col-lg-4">
                <label for="filter-type" class="form-label small mb-1">Demonstration</label>
                <select id="filter-type" name="type" class="form-select form-select-sm">
                    <option value="">Any demonstration</option>
                    @foreach (App\Enums\DemonstrationType::cases() as $option)
                        <option value="{{ $option->value }}" @selected(($filters['type'] ?? null) === $option->value)>{{ $option->demonstration()->title() }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-sm-6 col-lg-4">
                <label for="filter-category" class="form-label small mb-1">Classification</label>
                <select id="filter-category" name="category" class="form-select form-select-sm">
                    <option value="">Any classification</option>
                    @foreach (App\Enums\PayloadCategory::cases() as $option)
                        <option value="{{ $option->value }}" @selected(($filters['category'] ?? null) === $option->value)>{{ $option->label() }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-sm-6 col-lg-4">
                <label for="filter-vulnerable-status" class="form-label small mb-1">Vulnerable Mode</label>
                <select id="filter-vulnerable-status" name="vulnerable_status" class="form-select form-select-sm">
                    <option value="">Any status</option>
                    @foreach (['executed' => 'Executed', 'failed' => 'Failed', 'refused' => 'Not run (refused)'] as $value => $label)
                        <option value="{{ $value }}" @selected(($filters['vulnerable_status'] ?? null) === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-sm-6 col-lg-4">
                <label for="filter-secure-status" class="form-label small mb-1">Secure Mode</label>
                <select id="filter-secure-status" name="secure_status" class="form-select form-select-sm">
                    <option value="">Any status</option>
                    @foreach (['executed' => 'Executed', 'failed' => 'Failed'] as $value => $label)
                        <option value="{{ $value }}" @selected(($filters['secure_status'] ?? null) === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-sm-6 col-lg-4">
                <label for="filter-altered" class="form-label small mb-1">Outcome</label>
                <select id="filter-altered" name="altered" class="form-select form-select-sm">
                    <option value="">Any outcome</option>
                    @foreach (['yes' => 'Different results', 'no' => 'Same results', 'not_compared' => 'Not compared'] as $value => $label)
                        <option value="{{ $value }}" @selected(($filters['altered'] ?? null) === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-sm-6 col-lg-2">
                <label for="filter-from" class="form-label small mb-1">From</label>
                <input type="date" id="filter-from" name="from" value="{{ $filters['from'] ?? '' }}" class="form-control form-control-sm">
            </div>
            <div class="col-sm-6 col-lg-2">
                <label for="filter-to" class="form-label small mb-1">To</label>
                <input type="date" id="filter-to" name="to" value="{{ $filters['to'] ?? '' }}" class="form-control form-control-sm">
            </div>
        </div>
        <div class="d-flex flex-wrap align-items-center gap-2 mt-3">
            <button type="submit" class="btn btn-primary btn-sm">Apply filters</button>
            <a href="{{ $action }}" class="btn btn-outline-secondary btn-sm">Clear filters</a>
            <span class="small text-body-secondary">Dates use the {{ config('app.timezone') }} time zone.</span>
        </div>
    </div>
</form>
