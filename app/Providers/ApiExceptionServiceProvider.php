<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandlerBase;
use Illuminate\Http\Request;

class ApiExceptionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ExceptionHandler::class, function ($app) {
            return new class($app) extends ExceptionHandlerBase
            {
                protected function unauthenticated($request, AuthenticationException $exception)
                {
                    if ($request->is('api/*')) {
                        return response()->json(['message' => 'Unauthenticated'], 401);
                    }

                    // Si no es API, sigue el comportamiento original
                    return redirect()->guest(route('login'));
                }
            };
        });
    }
}
