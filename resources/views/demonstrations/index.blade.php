<x-layouts.app title="Demonstrations">
    <h1 class="h3 mb-1">SQL injection demonstrations</h1>
    <p class="text-body-secondary mb-4">
        Each demonstration runs the same input through a deliberately vulnerable query and a parameterized query,
        side by side, against fictitious lab data.
    </p>

    @unless ($vulnerableModeAvailable)
        <div class="alert alert-info" role="status">
            Vulnerable Mode is disabled because SQLiGuard is not running in the local lab environment. Only Secure Mode will run.
        </div>
    @endunless

    <div class="row g-4">
        @foreach ($types as $type)
            @php($definition = $type->demonstration())
            <div class="col-md-4">
                <div class="card h-100">
                    <div class="card-body d-flex flex-column">
                        <h2 class="h5 card-title">{{ $definition->title() }}</h2>
                        <p class="card-text small text-body-secondary">{{ $definition->scenario() }}</p>
                        <p class="card-text small">{{ $definition->whatItTests() }}</p>
                        <a href="{{ route('demonstrations.show', $type) }}" class="btn btn-primary mt-auto align-self-start">Open demonstration</a>
                    </div>
                </div>
            </div>
        @endforeach
    </div>
</x-layouts.app>
