<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Http\Middleware\CorsMiddleware; // <--- IMPORTANTE

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // 1) Ejecutar nuestro CORS ANTES que todo lo demás
        $middleware->prepend(CorsMiddleware::class);

        // 2) Lo que ya tenías
        $middleware->redirectGuestsTo(fn () => null);

        // reemplaza el Authenticate de Laravel por nuestro ApiAuthenticate
        $middleware->alias([
            'auth' => \App\Http\Middleware\ApiAuthenticate::class,
            'auth:sanctum' => \App\Http\Middleware\ApiAuthenticate::class,
        ]);
    })

    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })
    ->withProviders([
        //
    ])
    ->create();
