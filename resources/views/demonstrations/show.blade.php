<x-layouts.app :title="$demonstration->title()">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h1 class="h3 mb-0">{{ $demonstration->title() }}</h1>
        <nav aria-label="Demonstrations">
            <ul class="nav nav-pills small">
                @foreach ($types as $option)
                    <li class="nav-item">
                        <a class="nav-link py-1 @if ($option === $type) active @endif"
                           @if ($option === $type) aria-current="page" @endif
                           href="{{ route('demonstrations.show', $option) }}">{{ $option->demonstration()->title() }}</a>
                    </li>
                @endforeach
            </ul>
        </nav>
    </div>

    @unless ($vulnerableModeAvailable)
        <div class="alert alert-info" role="status">
            Vulnerable Mode is disabled because SQLiGuard is not running in the local lab environment. Only Secure Mode will run.
        </div>
    @endunless

    <div class="row g-4 mb-4">
        <div class="col-lg-5">
            <div class="card h-100">
                <div class="card-body">
                    <h2 class="h5">About this demonstration</h2>
                    <p class="small text-body-secondary">{{ $demonstration->scenario() }}</p>
                    <dl class="small mb-0">
                        <dt>What it tests</dt>
                        <dd>{{ $demonstration->whatItTests() }}</dd>
                        <dt class="text-danger-emphasis">Vulnerable behavior</dt>
                        <dd>{{ $demonstration->vulnerableBehavior() }}</dd>
                        <dt class="text-success-emphasis">Expected secure behavior</dt>
                        <dd class="mb-0">{{ $demonstration->secureBehavior() }}</dd>
                    </dl>
                </div>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="card h-100">
                <div class="card-body">
                    <h2 class="h5">Input</h2>
                    <p class="small text-body-secondary">
                        These values are submitted once and used, unchanged, by both modes.
                    </p>

                    <form method="POST" action="{{ route('demonstrations.run', $type) }}" novalidate>
                        @csrf

                        @foreach ($demonstration->fields() as $name => $field)
                            <div class="mb-3">
                                <label for="{{ $name }}" class="form-label">{{ $field['label'] }}</label>
                                <input type="text" id="{{ $name }}" name="{{ $name }}"
                                       value="{{ old($name, $values[$name] ?? '') }}"
                                       class="form-control font-monospace @error($name) is-invalid @enderror"
                                       maxlength="{{ App\Http\Requests\RunDemonstrationRequest::MAX_LENGTH }}"
                                       autocomplete="off" spellcheck="false" @if ($field['required']) required @endif>
                                <div class="form-text">{{ $field['help'] }}</div>
                                @error($name)
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        @endforeach

                        <button type="submit" class="btn btn-primary">Run comparison</button>
                    </form>

                    <h3 class="h6 mt-4">Sample inputs</h3>
                    <p class="small text-body-secondary mb-2">Choose a sample to fill in the form, then run the comparison.</p>
                    <ul class="list-unstyled small mb-0">
                        @foreach ($demonstration->samples() as $key => $sample)
                            <li class="mb-2">
                                <a href="{{ route('demonstrations.show', ['demonstration' => $type, 'sample' => $key]) }}"
                                   class="btn btn-sm {{ $sample['kind'] === 'attack' ? 'btn-outline-danger' : 'btn-outline-secondary' }}">
                                    <span class="badge {{ $sample['kind'] === 'attack' ? 'text-bg-danger' : 'text-bg-secondary' }} me-1">{{ $sample['kind'] === 'attack' ? 'Attack' : 'Normal' }}</span>
                                    <span class="font-monospace">{{ $sample['label'] }}</span>
                                </a>
                                <span class="text-body-secondary ms-1">{{ $sample['note'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </div>
    </div>

    @if ($comparison)
        @php($explanations = $comparison->explanations())
        @php($altered = $comparison->inputAlteredQuery())

        <section aria-labelledby="comparison-heading">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                <h2 id="comparison-heading" class="h4 mb-0">Comparison</h2>
                @if ($altered === true)
                    <span class="badge text-bg-danger fs-6">Input altered the vulnerable query</span>
                @elseif ($altered === false)
                    <span class="badge text-bg-success fs-6">Same behavior in both modes</span>
                @else
                    <span class="badge text-bg-secondary fs-6">Not compared</span>
                @endif
            </div>

            <div class="card mb-3">
                <div class="card-body py-2 small">
                    <span class="fw-semibold">Input used for both modes:</span>
                    @foreach ($comparison->input as $name => $value)
                        <span class="ms-2">{{ $demonstration->fields()[$name]['label'] }} = <code class="text-break">{{ $value }}</code></span>
                    @endforeach
                </div>
            </div>

            <div class="row g-4 mb-4">
                <div class="col-lg-6">
                    @include('demonstrations.partials.mode-panel', ['result' => $comparison->vulnerable, 'columns' => $demonstration->columns()])
                </div>
                <div class="col-lg-6">
                    @include('demonstrations.partials.mode-panel', ['result' => $comparison->secure, 'columns' => $demonstration->columns()])
                </div>
            </div>

            <div class="card">
                <div class="card-body">
                    <h2 class="h5">What happened and why</h2>
                    <dl class="mb-0">
                        <dt class="text-danger-emphasis">Vulnerable Mode</dt>
                        <dd>{{ $explanations['vulnerable'] }}</dd>
                        <dt class="text-success-emphasis">Secure Mode</dt>
                        <dd>{{ $explanations['secure'] }}</dd>
                        <dt>Why the modes differ</dt>
                        <dd>{{ $explanations['why'] }}</dd>
                        <dt>Security principle</dt>
                        <dd class="mb-0">{{ $explanations['principle'] }}</dd>
                    </dl>
                </div>
            </div>
        </section>
    @endif
</x-layouts.app>
