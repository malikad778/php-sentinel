# PHP Sentinel

Passive API Contract Monitoring for PHP 8.3+. Detects breaking changes in APIs you consume, entirely automatically.

## Installation

```bash
composer require malikad778/php-sentinel
```

## Basic Integration (PHP / PSR-18)

Inject the `SchemaWatcher` into your PSR-18 HTTP Client middleware stack.
Sentinel will silently monitor the JSON payloads returning from the APIs you consume, infer an authoritative JSON Schema (probabilistic model), and fire a PSR-14 event if the API shape changes.

```php
use Sentinel\Sentinel;
use Sentinel\Store\FileSchemaStore;
use Sentinel\Middleware\SchemaWatcher;
use Sentinel\Drift\DriftReporter;

$sentinel = Sentinel::create()
    ->withStore(new FileSchemaStore('/tmp/sentinel-schemas'))
    ->withSampleThreshold(20) // Require 20 samples to lock the schema baseline
    ->build();

// Use any PSR-14 dispatcher you already have
$reporter = new DriftReporter($psr14Dispatcher);

$watcher = new SchemaWatcher($psr18Client, $sentinel, $reporter);

// All API requests made through $watcher are now profiled for API drift!
$response = $watcher->sendRequest($request);
```

## Listening for Drift

Listen to the PSR-14 `SchemaDriftDetected` event within your application. Send an alert to Slack, Sentry, or pager duty!

```php
use Sentinel\Events\SchemaDriftDetected;

function handleDrift(SchemaDriftDetected $event) {
    if ($event->drift->severity->name === 'BREAKING') {
        Log::critical("API Drift on {$event->endpointKey} !!");
        foreach ($event->drift->changes as $change) {
            Log::info((string) $change);
        }
    }
}
```

## CLI Operations

```bash
# Profile an endpoint right now
php vendor/bin/sentinel profile --url="https://api.github.com/users/octocat" --samples=5

# View all schemas
php vendor/bin/sentinel list-schemas

# Inspect a generated JSON Schema
php vendor/bin/sentinel inspect "GET /users/{id}"
```

## Storage Backends

Out of the box, Sentinel supports:
- File System Storage (`FileSchemaStore`)
- PDO / MySQL (`PdoSchemaStore`)
- Redis (`RedisSchemaStore`)
- In Memory Array (`ArraySchemaStore`)
