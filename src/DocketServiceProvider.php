<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket;

use BJTheCod3r\Docket\Attachments\AttachmentManager;
use BJTheCod3r\Docket\Console\InstallCommand;
use BJTheCod3r\Docket\Console\PruneCommand;
use BJTheCod3r\Docket\Contracts\DocketEvent;
use BJTheCod3r\Docket\Contracts\ReferenceGenerator;
use BJTheCod3r\Docket\Contracts\VariantGenerator;
use BJTheCod3r\Docket\Events\EventMap;
use BJTheCod3r\Docket\Listeners\SendDocketNotifications;
use BJTheCod3r\Docket\Notifications\NotificationPipeline;
use BJTheCod3r\Docket\Proposals\ProposalService;
use BJTheCod3r\Docket\Rollup\RollupManager;
use BJTheCod3r\Docket\Statuses\StatusRegistry;
use BJTheCod3r\Docket\Statuses\TransitionGuard;
use BJTheCod3r\Docket\Support\KindRegistry;
use BJTheCod3r\Docket\Support\SequentialReferenceGenerator;
use BJTheCod3r\Docket\Suppression\SuppressionGate;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class DocketServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/docket.php', 'docket');

        $this->app->singleton(DocketManager::class);
        $this->app->singleton(StatusRegistry::class);
        $this->app->singleton(TransitionGuard::class);
        $this->app->singleton(KindRegistry::class);
        $this->app->singleton(EventMap::class, fn ($app) => new EventMap($app));
        $this->app->singleton(RollupManager::class, fn ($app) => new RollupManager($app));
        $this->app->singleton(AttachmentManager::class, fn ($app) => new AttachmentManager($app));
        $this->app->singleton(SuppressionGate::class);
        $this->app->singleton(NotificationPipeline::class);
        $this->app->singleton(ProposalService::class);
        $this->app->singleton(ReferenceGenerator::class, SequentialReferenceGenerator::class);

        if (is_string($generator = config('docket.attachments.variant_generator')) && class_exists($generator)) {
            $this->app->singleton(VariantGenerator::class, $generator);
        }
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'docket');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/docket.php' => config_path('docket.php'),
            ], 'docket-config');

            $this->publishes([
                __DIR__.'/../database/migrations/create_docket_tables.php.stub' => database_path(
                    'migrations/'.date('Y_m_d_His').'_create_docket_tables.php'
                ),
            ], 'docket-migrations');

            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/docket'),
            ], 'docket-views');

            $this->commands([
                InstallCommand::class,
                PruneCommand::class,
            ]);
        }

        Event::listen(DocketEvent::class, SendDocketNotifications::class);

        if (config('docket.api.enabled', false)) {
            Route::group([
                'prefix' => config('docket.api.prefix'),
                'middleware' => config('docket.api.middleware'),
                'as' => 'docket.',
            ], fn () => $this->loadRoutesFrom(__DIR__.'/../routes/api.php'));
        }
    }
}
