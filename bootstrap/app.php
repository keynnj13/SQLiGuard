<?php

use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Requests\RunDemonstrationRequest;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => EnsureUserHasRole::class,
        ]);

        $middleware->web(append: [
            EnsureAccountIsActive::class,
        ]);

        // Demonstration submissions must reach both modes exactly as typed,
        // including surrounding spaces. Trimming is skipped only for
        // POST /demonstrations/{tautology|union|comment}; every other request
        // is still trimmed. (TrimStrings runs before routing, so the match is
        // by method and path.)
        $middleware->trimStrings(except: [
            fn (Request $request): bool => RunDemonstrationRequest::isSubmission($request),
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
