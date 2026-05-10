<?php

namespace Nawasara\Notification;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Nawasara\Notification\Listeners\AlertOnSyncFailure;
use Nawasara\Notification\Services\NotificationService;
use Nawasara\Notification\Services\TemplateRenderer;
use Symfony\Component\Finder\Finder;

class NotificationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'nawasara-notification');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        // Guarded — Laravel's view:cache crashes on missing registered paths.
        if (is_dir(__DIR__.'/../resources/views/components')) {
            Blade::anonymousComponentPath(__DIR__.'/../resources/views/components', 'nawasara-notification');
        }
        $this->registerLivewire();
        $this->registerEventListeners();
    }

    /**
     * Subscribe to events from sister packages. Pakai class_exists guard
     * supaya kalau dependency optional (misal nawasara/sync) tidak ke-install,
     * tidak crash di boot.
     */
    protected function registerEventListeners(): void
    {
        if (class_exists(\Nawasara\Sync\Events\SyncJobFinalFailed::class)) {
            Event::listen(\Nawasara\Sync\Events\SyncJobFinalFailed::class, AlertOnSyncFailure::class);
        }
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/nawasara-notification.php', 'nawasara-notification');

        $this->app->singleton(TemplateRenderer::class, fn () => new TemplateRenderer());
        $this->app->singleton(NotificationService::class, fn ($app) => new NotificationService($app->make(TemplateRenderer::class)));
    }

    protected function registerLivewire(): void
    {
        $namespace = 'Nawasara\\Notification\\Livewire';
        $basePath = __DIR__.'/Livewire';

        if (! is_dir($basePath)) {
            return;
        }

        $finder = new Finder();
        $finder->files()->in($basePath)->name('*.php');

        foreach ($finder as $file) {
            $relativePath = str_replace('/', '\\', $file->getRelativePathname());
            $class = $namespace.'\\'.Str::beforeLast($relativePath, '.php');

            if (class_exists($class)) {
                $alias = 'nawasara-notification.'.
                    Str::of($relativePath)
                        ->replace('.php', '')
                        ->replace('\\', '.')
                        ->replace('/', '.')
                        ->explode('.')
                        ->map(fn ($segment) => Str::kebab($segment))
                        ->join('.');

                Livewire::component($alias, $class);
            }
        }
    }
}
