<?php

use Sentinel\Events\SchemaDriftDetected;
use Sentinel\Events\SchemaHardened;
use Sentinel\Drift\Changes\FieldRemoved;
use Sentinel\Drift\Severity;
use Sentinel\Sentinel;
use Sentinel\Store\ArraySchemaStore;

it('fires SchemaDriftDetected when a field is removed after hardening', function () {
    $firedEvent = null;
    $store      = new ArraySchemaStore();

    $dispatcher = new class implements \Psr\EventDispatcher\EventDispatcherInterface {
        public array $events = [];
        public function dispatch(object $event): object {
            $this->events[] = $event;
            return $event;
        }
    };

    $sentinel = Sentinel::create()
        ->withStore($store)
        ->withSampleThreshold(1)
        ->withDispatcher($dispatcher)
        ->build();

    // First call — hardens the schema with 'total_price' field
    $sentinel->process('GET', '/orders/1', 200, ['order' => ['id' => 1, 'total_price' => '99.00']]);

    // Second call — 'total_price' is gone, 'current_total_price' appeared
    $sentinel->process('GET', '/orders/2', 200, ['order' => ['id' => 2, 'current_total_price' => '99.00']]);

    $driftEvents = array_filter($dispatcher->events, fn($e) => $e instanceof SchemaDriftDetected);
    expect($driftEvents)->not->toBeEmpty();

    $drift = array_values($driftEvents)[0]->drift;
    expect($drift->severity)->toBe(Severity::BREAKING);

    $changeTypes = array_map(fn($c) => get_class($c), $drift->changes);
    expect($changeTypes)->toContain(FieldRemoved::class);
});

it('does not fire drift event when schemas are identical', function () {
    $store      = new ArraySchemaStore();
    $dispatcher = new class implements \Psr\EventDispatcher\EventDispatcherInterface {
        public array $events = [];
        public function dispatch(object $event): object { $this->events[] = $event; return $event; }
    };

    $sentinel = Sentinel::create()
        ->withStore($store)->withSampleThreshold(1)->withDispatcher($dispatcher)->build();

    $payload = ['order' => ['id' => 1, 'status' => 'paid']];
    $sentinel->process('GET', '/orders/1', 200, $payload);
    $sentinel->process('GET', '/orders/2', 200, $payload);  // identical shape

    $driftEvents = array_filter($dispatcher->events, fn($e) => $e instanceof SchemaDriftDetected);
    expect($driftEvents)->toBeEmpty();
});
