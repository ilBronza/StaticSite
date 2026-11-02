<?php

namespace IlBronza\StaticSite;

use Illuminate\Support\ServiceProvider;

final class StaticSiteServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/static-site.php', 'static-site');
        $this->app->singleton(ExportContext::class);
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../config/static-site.php' => config_path('static-site.php')], 'static-site.config');
        if ($this->app->runningInConsole()) {
            $this->commands([Console\BuildCommand::class, Console\RoutesCommand::class]);
        }
    }
}
