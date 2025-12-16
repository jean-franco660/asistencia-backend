<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\AsistenciaService;

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
        //
    }
}