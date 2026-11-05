<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Paginator::useBootstrapFive();

        // Password policy for every account password (web forms and the
        // create-admin command). The 72-character cap avoids bcrypt silently
        // ignoring anything past 72 bytes. No breach-list (uncompromised) check:
        // it calls an external API and the lab may run offline.
        Password::defaults(fn (): Password => Password::min(12)
            ->max(72)
            ->letters()
            ->mixedCase()
            ->numbers()
            ->symbols());

        // Every demonstration run opens a fresh lab database connection.
        // Limit runs per user so one account cannot exhaust connections.
        RateLimiter::for('demonstrations', fn (Request $request): Limit => Limit::perMinute(30)
            ->by((string) $request->user()?->getAuthIdentifier()));
    }
}
