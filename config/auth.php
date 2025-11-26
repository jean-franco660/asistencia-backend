<?php

return [

    'defaults' => [
        'guard' => env('AUTH_GUARD', 'web'),
        'passwords' => env('AUTH_PASSWORD_BROKER', 'usuarios_web'), // ⚠️ Cambia 'users' a 'usuarios_web'
    ],

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'usuarios_web',
        ],
    ],

    'providers' => [
        'usuarios_web' => [
            'driver' => 'eloquent',
            'model' => env('AUTH_MODEL', App\Models\UsuarioWeb::class),
        ],
    ],

    'passwords' => [
        'usuarios_web' => [ // ⚠️ Cambia 'users' a 'usuarios_web'
            'provider' => 'usuarios_web', // ⚠️ Cambia 'users' a 'usuarios_web'
            'table' => env('AUTH_PASSWORD_RESET_TOKEN_TABLE', 'password_reset_tokens'),
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),

];