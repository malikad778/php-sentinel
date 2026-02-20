<?php

declare(strict_types=1);

namespace Sentinel\Laravel;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Sentinel\Events\SchemaDriftDetected;

class SentinelServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Bind the Sentinel singleton
        $this->app->singleton(\Sentinel\Sentinel::class, function ($app) {
            // Read from Laravel config
            // $config = $app['config']['sentinel'];
            
            return \Sentinel\Sentinel::create()
                // ->withStore(...)
                ->build();
        });
    }

    public function boot(): void
    {
        // Listen to native PSR-14 dispatched events and pipe to Laravel's event bus if necessary
        // Or natively listen to SchemaDriftDetected if the reporter dispatches through Laravel's dispatcher automatically.
        
        // Setup HTTP Client Macro
        /*
        \Illuminate\Support\Facades\Http::macro('withSentinel', function () {
            // attach Guzzle middleware
            return $this;
        });
        */
        
        // Register console commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                // \Sentinel\Laravel\Commands\ProfileCommand::class,
            ]);
        }
    }
}
