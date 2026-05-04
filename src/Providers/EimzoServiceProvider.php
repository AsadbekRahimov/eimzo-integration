<?php

namespace AsadbekRahimov\EimzoIntegration\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use AsadbekRahimov\EimzoIntegration\Http\Controllers\EimzoAssetController;
use AsadbekRahimov\EimzoIntegration\Services\EimzoAuthService;
use AsadbekRahimov\EimzoIntegration\Services\EimzoMobileService;
use AsadbekRahimov\EimzoIntegration\Services\EimzoServerClient;
use AsadbekRahimov\EimzoIntegration\Services\EimzoSignService;
use AsadbekRahimov\EimzoIntegration\Services\EimzoTimestampService;
use AsadbekRahimov\EimzoIntegration\Services\EimzoVerifyService;
use AsadbekRahimov\EimzoIntegration\Services\Pkcs7Parser;

class EimzoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom($this->path('config/eimzo.php'), 'eimzo');

        $this->app->singleton(Pkcs7Parser::class);
        $this->app->singleton(EimzoServerClient::class);
        $this->app->singleton(EimzoAuthService::class);
        $this->app->singleton(EimzoSignService::class);
        $this->app->singleton(EimzoVerifyService::class);
        $this->app->singleton(EimzoTimestampService::class);
        $this->app->singleton(EimzoMobileService::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom($this->path('database/migrations'));
        $this->loadViewsFrom($this->path('resources/views'), 'eimzo');

        $this->registerRoutes();
        $this->registerPublishing();
    }

    private function registerRoutes(): void
    {
        $this->registerAssetRoutes();

        if (! config('eimzo.routes.enabled', true)) {
            return;
        }

        Route::group([
            'prefix' => trim((string) config('eimzo.routes.prefix', 'eimzo'), '/'),
            'middleware' => (array) config('eimzo.routes.middleware', ['web']),
            'as' => 'eimzo.',
        ], function () {
            $this->loadRoutesFrom($this->path('routes/web.php'));
        });

        Route::group([
            'prefix' => trim((string) config('eimzo.routes.api_prefix', 'api/eimzo'), '/'),
            'middleware' => (array) config('eimzo.routes.api_middleware', ['api']),
            'as' => 'eimzo.api.',
        ], function () {
            $this->loadRoutesFrom($this->path('routes/api.php'));
        });
    }

    private function registerAssetRoutes(): void
    {
        if (! config('eimzo.assets.enabled', true)) {
            return;
        }

        Route::middleware((array) config('eimzo.assets.middleware', []))
            ->get('vendor/eimzo/{path}', EimzoAssetController::class)
            ->where('path', '.*')
            ->name('eimzo.assets');
    }

    private function registerPublishing(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            $this->path('config/eimzo.php') => config_path('eimzo.php'),
        ], 'eimzo-config');

        $this->publishes([
            $this->path('database/migrations') => database_path('migrations'),
        ], 'eimzo-migrations');

        $this->publishes([
            $this->path('resources/views') => resource_path('views/vendor/eimzo'),
        ], 'eimzo-views');

        $this->publishes([
            $this->path('resources/js') => public_path('vendor/eimzo'),
        ], 'eimzo-assets');
    }

    private function path(string $relative): string
    {
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . ltrim($relative, '/');
    }
}
