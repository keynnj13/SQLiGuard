<x-layouts.app title="Home">
    <div class="py-4">
        <h1 class="h2 mb-2">{{ config('app.name') }}</h1>
        <p class="lead text-body-secondary mb-4">
            A web input security demonstrator comparing vulnerable and parameterized queries against SQL injection
            in a controlled lab environment.
        </p>

        <div class="row g-4">
            <div class="col-md-6">
                <div class="card h-100 border-danger-subtle">
                    <div class="card-header bg-danger-subtle text-danger-emphasis fw-semibold">Vulnerable Mode</div>
                    <div class="card-body">
                        <p class="card-text mb-0">
                            Builds the SQL query by concatenating user input into the query text, so crafted input can
                            change the structure of the query. It runs only locally, through a restricted read-only
                            lab account, against fake demonstration data.
                        </p>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card h-100 border-success-subtle">
                    <div class="card-header bg-success-subtle text-success-emphasis fw-semibold">Secure Mode</div>
                    <div class="card-body">
                        <p class="card-text mb-0">
                            Sends the same input as a bound parameter, separate from the query text, so the input is
                            always treated as data and can never change the intended query structure.
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <p class="text-body-secondary small mt-4 mb-0">
            Both modes run the same input side by side so their queries, results, and explanations can be compared directly.
        </p>
    </div>
</x-layouts.app>
