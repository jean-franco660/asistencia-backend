<?php

namespace App\Providers;

use App\Models\UsuarioAppInstitucion;
use App\Models\HorarioInstitucion;
use App\Observers\UsuarioAppInstitucionObserver;
use App\Observers\HorarioInstitucionObserver;
use Illuminate\Support\ServiceProvider;
use App\Services\AsistenciaService;
use Illuminate\Database\Eloquent\Relations\Relation;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Si tienes el singleton registrado:
        $this->app->singleton(AsistenciaService::class, function ($app) {
            return new AsistenciaService();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // ✅ Registrar Observer para gestión automática de vigencia
        UsuarioAppInstitucion::observe(UsuarioAppInstitucionObserver::class);

        // ✅ Registrar Observer para auto-asignación de horarios a usuarios
        HorarioInstitucion::observe(HorarioInstitucionObserver::class);

        Relation::morphMap([
            'UsuarioWeb' => \App\Models\UsuarioWeb::class,
            'UsuarioApp' => \App\Models\UsuarioApp::class,
            'UsuarioAppInstitucion' => UsuarioAppInstitucion::class,
        ]);
    }
}