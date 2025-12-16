<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class RateLimitServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // THROTTLE PARA LOGIN - 5 intentos por minuto
        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(5)
                ->by($request->input('email') ?? $request->input('codigo') ?? $request->ip())
                ->response(function () {
                    return response()->json([
                        'message' => 'Demasiados intentos de inicio de sesión. Intenta de nuevo en 1 minuto.'
                    ], 429);
                });
        });

        // THROTTLE PARA ACCIONES CRÍTICAS - 30 intentos por minuto
        RateLimiter::for('acciones-criticas', function (Request $request) {
            return Limit::perMinute(30)
                ->by($request->user()?->id ?? $request->ip())
                ->response(function () {
                    return response()->json([
                        'message' => 'Demasiadas acciones críticas. Intenta de nuevo en 1 minuto.'
                    ], 429);
                });
        });

        // THROTTLE PARA IMPORTACIONES - 3 intentos por minuto
        RateLimiter::for('importaciones', function (Request $request) {
            $userPart = $request->user()?->id ? "user:{$request->user()->id}" : "ip:{$request->ip()}";
            $pathPart = str_contains($request->path(), 'instituciones') ? 'instituciones' : 'docentes';

            return Limit::perMinute(10)
                ->by("{$userPart}:{$pathPart}")
                ->response(fn () => response()->json([
                    'message' => 'Demasiadas importaciones. Intenta de nuevo en 1 minuto.'
                ], 429));
        });


        // THROTTLE GENERAL API - 60 intentos por minuto
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)
                ->by($request->user()?->id ?? $request->ip())
                ->response(function () {
                    return response()->json([
                        'message' => 'Demasiadas peticiones. Intenta de nuevo en 1 minuto.'
                    ], 429);
                });
        });
    }
}