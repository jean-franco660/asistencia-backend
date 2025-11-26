<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Auth\AuthenticationException;

class ApiAuthenticate extends Middleware
{
    protected function unauthenticated($request, array $guards)
    {
        // Si la ruta es API, responder JSON, no redirigir
        if ($request->is('api/*')) {
            throw new AuthenticationException('Unauthenticated', $guards);
        }

        // Si es web, entonces sí intenta login normal
        return redirect()->guest(route('login'));
    }
}
