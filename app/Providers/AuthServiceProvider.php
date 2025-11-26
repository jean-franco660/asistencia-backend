<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    protected $policies = [
        \App\Models\HorarioInstitucion::class => \App\Policies\HorarioInstitucionPolicy::class,
        \App\Models\UsuarioApp::class        => \App\Policies\UsuarioAppPolicy::class,
        \App\Models\UsuarioWeb::class        => \App\Policies\UsuarioWebPolicy::class,
        \App\Models\Institucion::class       => \App\Policies\InstitucionPolicy::class,
        \App\Models\Asistencia::class        => \App\Policies\AsistenciaPolicy::class,
    ];

    public function boot(): void
    {
        $this->registerPolicies(); 
    }
}
