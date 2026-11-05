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
                        @auth
                            @php($dashboardRoute = auth()->user()->role->homeRoute())
                            <li class="nav-item">
                                <a class="nav-link @if (request()->routeIs($dashboardRoute)) active @endif"
                                   @if (request()->routeIs($dashboardRoute)) aria-current="page" @endif
                                   href="{{ route($dashboardRoute) }}">Dashboard</a>
                            </li>
                            @can('viewAny', App\Models\User::class)
                                <li class="nav-item">
                                    <a class="nav-link @if (request()->routeIs('admin.users.*')) active @endif"
                                       @if (request()->routeIs('admin.users.*')) aria-current="page" @endif
                                       href="{{ route('admin.users.index') }}">Accounts</a>
                                </li>
                            @endcan
                        @else
                            <li class="nav-item">
                                <a class="nav-link @if (request()->routeIs('home')) active @endif"
                                   @if (request()->routeIs('home')) aria-current="page" @endif
                                   href="{{ route('home') }}">Home</a>
                            </li>
                        @endauth
                    </ul>
                    <ul class="navbar-nav align-items-md-center">
                        @auth
                            <li class="nav-item">
                                <span class="navbar-text me-md-3">
                                    {{ auth()->user()->name }}
                                    <span class="badge text-bg-secondary ms-1">{{ auth()->user()->role->label() }}</span>
                                </span>
                            </li>
                            <li class="nav-item">
                                <form method="POST" action="{{ route('logout') }}" class="d-inline">
                                    @csrf
                                    <button type="submit" class="btn btn-outline-light btn-sm">Log out</button>
                                </form>
                            </li>
                        @else
                            <li class="nav-item">
                                <a class="nav-link @if (request()->routeIs('login')) active @endif" href="{{ route('login') }}">Log in</a>
                            </li>
                        @endauth
                    </ul>
                </div>
            </div>
        </nav>

        <x-lab-warning />

        <main class="container flex-grow-1 py-4">
            @if (session('status'))
                <div class="alert alert-success alert-dismissible fade show" role="status">
                    {{ session('status') }}
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            @endif

            {{ $slot }}
        </main>

        <footer class="border-top bg-body py-3">
            <div class="container small text-body-secondary">
                {{ config('app.name') }} &middot; SQL injection demonstrator for a controlled lab environment. All demonstration data is fictitious.
            </div>
        </footer>
    </body>
</html>
