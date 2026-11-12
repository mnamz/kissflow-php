<?php

declare(strict_types=1);

namespace Aliifz\Kissflow\Laravel;

use Aliifz\Kissflow\Config;
use Aliifz\Kissflow\KissflowClient;
use Illuminate\Support\ServiceProvider;

/**
 * Auto-discovered by Laravel. Binds KissflowClient as a lazy singleton from config('kissflow').
 */
class KissflowServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/kissflow.php', 'kissflow');

        $this->app->singleton(KissflowClient::class, function ($app) {
            $config = $app['config']->get('kissflow', []);
            $channel = $config['log_channel'] ?? null;
            $logger = $channel ? $app['log']->channel($channel) : $app['log'];

            return new KissflowClient(Config::fromArray($config), null, $logger);
        });

        $this->app->alias(KissflowClient::class, 'kissflow');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../../config/kissflow.php' => $this->app->configPath('kissflow.php'),
            ], 'kissflow-config');
        }
    }
}
