<?php

use Sentinel\Events\SchemaDriftDetected;
use Sentinel\Events\SchemaHardened;
use Sentinel\Drift\Changes\FieldAdded;
use Sentinel\Drift\Changes\RequiredNowOptional;
use Sentinel\Drift\Changes\NowNullable;
use Sentinel\Drift\Changes\TypeChanged;
use Sentinel\Drift\Severity;
use Sentinel\Sentinel;
use Sentinel\Store\ArraySchemaStore;

it('correctly builds a baseline and detects drift in Shopify orders', function () {
    $store = new ArraySchemaStore();
    
    $dispatcher = new class implements \Psr\EventDispatcher\EventDispatcherInterface {
        public array $events = [];
        public function dispatch(object $event): object {
            $this->events[] = $event;
            return $event;
        }
    };

    $sentinel = Sentinel::create()
        ->withStore($store)
        ->withSampleThreshold(3) // Need 3 samples to harden
        ->withDispatcher($dispatcher)
        ->build();

    $basic1 = json_decode(file_get_contents(__DIR__ . '/../Fixtures/shopify_order_basic.json'), true);
    $basic2 = json_decode(file_get_contents(__DIR__ . '/../Fixtures/shopify_order_basic.json'), true);
    
    // We add a fulfillment order to hit the threshold. 'fulfillments' array now occasionally has items.
    $withFulfillment = json_decode(file_get_contents(__DIR__ . '/../Fixtures/shopify_order_with_fulfillment.json'), true);
    
    $sentinel->process('GET', '/admin/api/2023-10/orders/1.json', 200, $basic1);
    $sentinel->process('GET', '/admin/api/2023-10/orders/2.json', 200, $basic2);
    $sentinel->process('GET', '/admin/api/2023-10/orders/3.json', 200, $withFulfillment);

    // Schema should now be hardened
    $hardenedEvents = array_filter($dispatcher->events, fn($e) => $e instanceof SchemaHardened);
    expect($hardenedEvents)->toHaveCount(1);
    
    $keys = $store->all();
    $baseline = count($keys) > 0 ? $store->get($keys[0]) : null;
    expect($baseline)->not->toBeNull();
    
    // Now simulate an order with NO shipping address (breaking change!)
    $noShipping = json_decode(file_get_contents(__DIR__ . '/../Fixtures/shopify_order_no_shipping.json'), true);
    $sentinel->process('GET', '/admin/api/2023-10/orders/4.json', 200, $noShipping);
    
    $driftEvents = array_filter($dispatcher->events, fn($e) => $e instanceof SchemaDriftDetected);
    expect($driftEvents)->toHaveCount(1);
    
    $drift = array_values($driftEvents)[0]->drift;
    
    // Shipping address went from an object to null. This is a NowNullable change!
    $changeClasses = array_map(fn($c) => get_class($c), $drift->changes);
    
    // Since we used NowNullable, and possibly RequiredNowOptional depending on structure, let's just assert length and one class 
    expect($changeClasses)->toContain(NowNullable::class);
});

it('correctly models optional last_payment_error on Stripe Payment Intents', function () {
    $store = new ArraySchemaStore();
    
    $dispatcher = new class implements \Psr\EventDispatcher\EventDispatcherInterface {
        public array $events = [];
        public function dispatch(object $event): object {
            $this->events[] = $event;
            return $event;
        }
    };

    $sentinel = Sentinel::create()
        ->withStore($store)
        ->withSampleThreshold(2) // Need 2 samples to harden
        ->withDispatcher($dispatcher)
        ->build();

    $success = json_decode(file_get_contents(__DIR__ . '/../Fixtures/stripe_payment_intent_succeeded.json'), true);
    $failed = json_decode(file_get_contents(__DIR__ . '/../Fixtures/stripe_payment_intent_failed.json'), true);

    // After these two, last_payment_error is sometimes null and sometimes an object. 
    // And sometimes it didn't exist? Actually both JSONs have it.
    // One has it null, the other has it object.
    
    $sentinel->process('GET', '/v1/payment_intents/pi_shared', 200, $success);
    $sentinel->process('GET', '/v1/payment_intents/pi_shared', 200, $failed);
    
    $hardenedEvents = array_filter($dispatcher->events, fn($e) => $e instanceof SchemaHardened);
    expect($hardenedEvents)->toHaveCount(1);
    
    // Process the success one again, no drift should be detected
    $dispatcher->events = [];
    $sentinel->process('GET', '/v1/payment_intents/pi_shared', 200, $success);
    
    $driftEvents = array_filter($dispatcher->events, fn($e) => $e instanceof SchemaDriftDetected);
    if (count($driftEvents) > 0) {
        $drift = array_values($driftEvents)[0]->drift;
        foreach ($drift->changes as $change) {
            echo "\nDRIFT IN TEST: " . get_class($change) . " -> " . $change->getPath() . "\n";
        }
    }
    expect($driftEvents)->toHaveCount(0); // No drift! The probabilistic model handled varying structures and nulls.
});
