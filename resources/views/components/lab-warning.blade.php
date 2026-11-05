{{--
    Vulnerable Mode may only ever be available in the local environment, so the
    warning is shown whenever the app runs locally. When the administrator
    toggle is added, this condition must stay at least this strict.
--}}
@if (app()->environment('local'))
    <div class="alert alert-warning border-0 border-bottom border-warning rounded-0 mb-0" role="alert">
        <div class="container">
            <strong>Lab environment warning:</strong>
            Vulnerable Mode is available in this local environment. It intentionally executes unsafe SQL against
            fake demonstration data for teaching purposes only. Never deploy this application to a public or production server.
        </div>
    </div>
@endif
