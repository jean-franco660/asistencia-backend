<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // ✅ Usa el HandleCors de Laravel
        $middleware->prepend(\Illuminate\Http\Middleware\HandleCors::class);

        $middleware->redirectGuestsTo(fn () => null);

        $middleware->alias([
            'auth' => \App\Http\Middleware\ApiAuthenticate::class,
            'auth:sanctum' => \App\Http\Middleware\ApiAuthenticate::class,
        ]);
    })

    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })
    ->withProviders([
        \App\Providers\RateLimitServiceProvider::class,
    ])
    ->create();