@props(['title' => null])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ $title ? $title.' · '.config('app.name') : config('app.name') }}</title>

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="d-flex flex-column min-vh-100 bg-body-tertiary">
        <nav class="navbar navbar-expand-md bg-dark border-bottom border-body" data-bs-theme="dark">
            <div class="container">
                <a class="navbar-brand fw-semibold" href="{{ route('home') }}">{{ config('app.name') }}</a>
                <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav"
                        aria-controls="mainNav" aria-expanded="false" aria-label="Toggle navigation">
                    <span class="navbar-toggler-icon"></span>
                </button>
                <div class="collapse navbar-collapse" id="mainNav">
                    <ul class="navbar-nav me-auto">
                        <li class="nav-item">
                            <a class="nav-link @if (request()->routeIs('home')) active @endif"
                               @if (request()->routeIs('home')) aria-current="page" @endif
                               href="{{ route('home') }}">Home</a>
                        </li>
                    </ul>
                </div>
            </div>
        </nav>

        <x-lab-warning />

        <main class="container flex-grow-1 py-4">
            {{ $slot }}
        </main>

        <footer class="border-top bg-body py-3">
            <div class="container small text-body-secondary">
                {{ config('app.name') }} &middot; SQL injection demonstrator for a controlled lab environment. All demonstration data is fictitious.
            </div>
        </footer>
    </body>
</html>
