# PHP Sentinel 🛡️

[![Latest Version on Packagist](https://img.shields.io/packagist/v/malikad778/php-sentinel.svg)](https://packagist.org/packages/malikad778/php-sentinel)
[![Tests](https://github.com/malikad778/php-sentinel/actions/workflows/tests.yml/badge.svg)](https://github.com/malikad778/php-sentinel/actions/workflows/tests.yml)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](https://opensource.org/licenses/MIT)
[![PHP Version Require](https://img.shields.io/packagist/php-v/malikad778/php-sentinel.svg)](https://packagist.org/packages/malikad778/php-sentinel)

Passive API Contract Monitoring for strictly typed PHP 8.3+.  

Sentinel silently monitors the JSON payloads returning from the third-party APIs you consume, automatically infers their structural JSON Schema, and detects when they change unexpectedly (drift). 

## ❓ What Is It & What Does It Do?

When you integrate with external REST APIs, you build your internal systems, DTOs, and mappings around the structural "contract" of their responses. But APIs change—fields become nullable, new enum values appear, or keys are dropped entirely. Usually, you don't find out until your app crashes in production.

**PHP Sentinel solves this.** It acts as a passive proxy middleware on your HTTP clients (like Guzzle).
1. **Sampling:** It watches the first *N* successful JSON responses from an endpoint and probabilistically infers the underlying JSON Schema (figuring out which fields are required, optional, null, what the enums are, and the exact nesting structure).
2. **Hardening:** After enough samples, it locks in a "Baseline Schema" and stores it.
3. **Drift Detection:** On all future requests, Sentinel compares the live response against your locked baseline. If the API adds, removes, or changes the type of any field, Sentinel instantly detects the drift.
4. **Alerting:** Sentinel logs the drift to your PSR-3 Logger (e.g., Laravel's or Symfony's logger) and dispatches a PSR-14 Event so you can alert your team via Slack, Sentry, or email *before* it breaks your app.

## ✨ Features

* **Zero-Touch Inference:** Automatically deduces deep JSON Schemas containing `types`, `nested properties`, `required/optional fields`, `enums`, and string `formats` (like UUIDs, Datetimes) just from looking at data.
* **Smart Drift Detection:** Differentiates between:
  * `BREAKING` changes (fields removed, types changed, previously non-null fields returning null)
  * `ADDITIVE` changes (new fields added)
  * `ADVISORY` changes (formats changed)
* **Framework Native Integrations:** Ships with deep auto-wiring support for **Laravel** (Service Providers & Http Macros) and **Symfony** (Bundles & Dependency Injection Extensions).
* **PSR Standard Compliant:** Integrates directly with PSR-18 (HTTP Clients), PSR-14 (Event Dispatchers), and PSR-3 (Loggers).
* **Multiple Storage Backends:** Store your schemas safely in `Redis`, relational databases via `PDO`, flat `Files`, or `In-Memory Arrays` for testing.
* **CLI Toolkit:** Includes a robust set of `symfony/console` commands to manually profile URLs, inspect baselines, list active tracked endpoints, and compare local schemas.

---

## 💻 How Developers Can Utilize This

### Installation

```bash
composer require malikad778/php-sentinel
```

### 1. The Laravel Way 🔴

Sentinel natively integrates with Laravel and the `Http::` facade.

Just publish the config:
```bash
php artisan vendor:publish --tag=sentinel-config
```

Then attach Sentinel to any outgoing API request using the `withSentinel()` macro!
```php
use Illuminate\Support\Facades\Http;

$response = Http::withSentinel()
    ->withToken('stripe-key')
    ->get('https://api.stripe.com/v1/payment_intents/pi_123');
```
As traffic flows through, Sentinel will automatically store schemas in your configured database or Redis, and write warnings directly into your Laravel `Log`.

### 2. The Symfony Way 🎹

Enable the `SentinelBundle` in your `config/bundles.php`:
```php
return [
    Sentinel\Symfony\SentinelBundle::class => ['all' => true],
];
```

Define your settings in `config/packages/sentinel.yaml`:
```yaml
sentinel:
    store:
        driver: redis # or pdo, file
    sample_threshold: 30
    drift_severity: BREAKING
    reharden: true
```
The bundle automatically wires the `Sentinel` singleton into your Dependency Injection container so you can inject it anywhere.

### 3. The Framework-Agnostic Way (PSR-18)

Inject `SchemaWatcher` into any PSR-18 middleware stack (like Guzzle).

```php
use Sentinel\Sentinel;
use Sentinel\Store\FileSchemaStore;
use Sentinel\Middleware\SchemaWatcher;
use Sentinel\Drift\DriftReporter;

$sentinel = Sentinel::create()
    ->withStore(new FileSchemaStore('/tmp/schemas'))
    ->withSampleThreshold(20)
    // ->withLogger($myMonologInstance)
    // ->withDispatcher($myPsr14Dispatcher)
    ->build();

// Wrap your HTTP Client
$watcher = new SchemaWatcher($psr18Client, $sentinel, new DriftReporter($sentinel->getDispatcher(), $sentinel->getLogger()));

// Use $watcher exactly like your normal HTTP client!
$response = $watcher->sendRequest($request);
```

---

## 🛠 Project Architecture & Structure

The framework was built with strict modularity, targeting PHPStan Level 8.

- **`src/Inference/`**: The deductive engine. Houses `InferenceEngine`, `TypeResolver`, and `EnumCandidateDetector` which convert raw JSON payloads into strict structural schemas.
- **`src/Sampling/`**: Contains the `SampleAccumulator` which pools traffic over time, calculating fractional presences (to map `optional` vs `required` fields) before hardening the schema.
- **`src/Drift/`**: The diffing engine. Contains `DriftDetector` and granular `Changes/*` classes (e.g. `NowNullable`, `TypeChanged`) to calculate exact JSON diffs.
- **`src/Middleware/`**: Wrappers for the `SchemaWatcher` that hook into HTTP lifecycles.
- **`src/Store/`**: Storage drivers implementing `SchemaStoreInterface`.
- **`src/Console/`**: `symfony/console` commands.
- **`src/Laravel/` & `src/Symfony/`**: Adapters to seamlessly bind the library to enterprise frameworks natively.

---

## 🚨 Responding to Drift Programmatically

While Sentinel logs drift warnings out-of-the-box, you probably want to trigger custom actions (like paging an on-call engineer) when a breaking change happens.

Sentinel broadcasts the `Sentinel\Events\SchemaDriftDetected` PSR-14 event. Listen to it in your app:

```php
use Sentinel\Events\SchemaDriftDetected;

public function handleAPIChange(SchemaDriftDetected $event): void 
{
    if ($event->drift->severity->value === 'BREAKING') {
        // The API broke its contract!
        $endpoint = $event->drift->endpoint;
        
        foreach ($event->drift->changes as $change) {
            echo "Field: " . $change->getPath() . " -> " . $change->getDescription();
        }
        
        // PagerDuty::trigger("API Drift on $endpoint");
    }
}
```

## 🧰 CLI Tools

Sentinel comes with a standalone executable (`bin/sentinel`) and registers identical artisan/console commands into your framework.

```bash
# Profile an endpoint blindly 5 times to force a schema generation
php vendor/bin/sentinel profile --url="https://api.github.com/users/octocat" --samples=5

# View all actively monitored endpoints and their sampling status
php vendor/bin/sentinel list-schemas

# View the full JSON Schema inferred for an endpoint
php vendor/bin/sentinel inspect "GET /users/{id}"

# Compare two generated JSON schemas manually
php vendor/bin/sentinel diff old_schema.json new_schema.json
```
