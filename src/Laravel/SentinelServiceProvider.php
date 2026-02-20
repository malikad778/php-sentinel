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
        $this->mergeConfigFrom(__DIR__ . '/../../config/sentinel.php', 'sentinel');

        $this->app->singleton(\Sentinel\Sentinel::class, function ($app) {
            $config = $app['config']['sentinel'] ?? [];
            
            $driver = $config['store']['driver'] ?? 'file';
            
            $storagePath = isset($app['path.storage']) ? (string) $app['path.storage'] : sys_get_temp_dir();
            
            $store = match($driver) {
                'file'  => new \Sentinel\Store\FileSchemaStore($config['store']['path'] ?? $storagePath . '/sentinel'),
                'redis' => new \Sentinel\Store\RedisSchemaStore($app->make('redis')->connection()),
                'pdo'   => new \Sentinel\Store\PdoSchemaStore($app->make('db')->getPdo()),
                default => new \Sentinel\Store\FileSchemaStore($storagePath . '/sentinel'),
            };

            return \Sentinel\Sentinel::create()
                ->withStore($store)
                ->withSampleThreshold((int) ($config['sample_threshold'] ?? 20))
                ->withDriftSeverity(\Sentinel\Drift\Severity::from($config['drift_severity'] ?? 'BREAKING'))
                ->withDispatcher(new class($app['events']) implements \Psr\EventDispatcher\EventDispatcherInterface {
                    public function __construct(private mixed $laravelDispatcher) {}
                    public function dispatch(object $event): object {
                        if (is_callable([$this->laravelDispatcher, 'dispatch'])) {
                            call_user_func([$this->laravelDispatcher, 'dispatch'], $event);
                        }
                        return $event;
                    }
                })
                ->build();
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $configPath = isset($this->app['path.config']) ? (string) $this->app['path.config'] . '/sentinel.php' : __DIR__ . '/sentinel.php';
            $this->publishes([
                __DIR__ . '/../../config/sentinel.php' => $configPath,
            ], 'sentinel-config');
            
            $this->commands([
                \Sentinel\Console\Commands\ProfileCommand::class,
                \Sentinel\Console\Commands\DiffCommand::class,
                \Sentinel\Console\Commands\InspectCommand::class,
                \Sentinel\Console\Commands\ListCommand::class,
            ]);
        }

        \Illuminate\Support\Facades\Http::macro('withSentinel', function () {
            /** @var mixed $this */
            $app = function_exists('app') ? app() : null;
            if (!$app) {
                return $this;
            }
            $sentinel = $app->make(\Sentinel\Sentinel::class);
            $reporter = new \Sentinel\Drift\DriftReporter($sentinel->getDispatcher());
            
            /** @phpstan-ignore-next-line */
            return $this->withOptions([
                'handler' => tap(\GuzzleHttp\HandlerStack::create(), function($stack) use ($sentinel, $reporter) {
                    $watcher = function (callable $handler) use ($sentinel, $reporter) {
                        return function (\Psr\Http\Message\RequestInterface $request, array $options) use ($handler, $sentinel) {
                            $promise = $handler($request, $options);
                            return $promise->then(
                                function (\Psr\Http\Message\ResponseInterface $response) use ($request, $sentinel, $reporter) {
                                    $body = (string) $response->getBody();
                                    $decoded = json_decode($body, true);
                                    if (is_array($decoded)) {
                                        $sentinel->process($request->getMethod(), (string) $request->getUri(), $response->getStatusCode(), $decoded);
                                    }
                                    return $response;
                                }
                            );
                        };
                    };
                    $stack->push($watcher);
                })
            ]);
        });
    }
}
