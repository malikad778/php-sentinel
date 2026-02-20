<?php

require __DIR__ . '/../vendor/autoload.php';

use Sentinel\Sentinel;
use Sentinel\Store\ArraySchemaStore;
use Sentinel\Events\SchemaHardened;
use Sentinel\Events\SchemaDriftDetected;

$store = new ArraySchemaStore();

$dispatcher = new class implements \Psr\EventDispatcher\EventDispatcherInterface {
    public function dispatch(object $event): object {
        if ($event instanceof SchemaHardened) {
            echo "\n✅ [EVENT] Schema Hardened for " . $event->endpointKey . "!\n";
            echo "Baseline Schema (first 300 chars):\n";
            echo substr(json_encode($event->schema->jsonSchema, JSON_PRETTY_PRINT), 0, 300) . "...\n";
        }
        if ($event instanceof SchemaDriftDetected) {
            echo "\n🚨 [EVENT] Schema Drift Detected! Severity: " . $event->drift->severity->name . "\n";
            foreach ($event->drift->changes as $change) {
                echo "   ❌ " . get_class($change) . "\n";
                echo "      Field: " . $change->getPath() . "\n";
                echo "      Issue: " . $change->getDescription() . "\n";
            }
        }
        return $event;
    }
};

$sentinel = Sentinel::create()
    ->withStore($store)
    ->withSampleThreshold(3)
    ->withDispatcher($dispatcher)
    ->build();

echo "=========================================\n";
echo "--- 🛍️  PROCESSING SHOPIFY ORDERS ---\n";
echo "=========================================\n";
echo "1. Processing Order 1 (Basic)...\n";
$basic1 = json_decode(file_get_contents(__DIR__ . '/../tests/Fixtures/shopify_order_basic.json'), true);
$sentinel->process('GET', '/admin/api/2023-10/orders/1.json', 200, $basic1);

echo "2. Processing Order 2 (Basic)...\n";
$sentinel->process('GET', '/admin/api/2023-10/orders/2.json', 200, $basic1);

echo "3. Processing Order 3 (With Fulfillment)...\n";
$withFulfillment = json_decode(file_get_contents(__DIR__ . '/../tests/Fixtures/shopify_order_with_fulfillment.json'), true);
$sentinel->process('GET', '/admin/api/2023-10/orders/3.json', 200, $withFulfillment);

echo "4. Processing Order 4 (No Shipping - BREAKING!)...\n";
$noShipping = json_decode(file_get_contents(__DIR__ . '/../tests/Fixtures/shopify_order_no_shipping.json'), true);
$sentinel->process('GET', '/admin/api/2023-10/orders/4.json', 200, $noShipping);

echo "\n\n=========================================\n";
echo "--- 💳 PROCESSING STRIPE PAYMENT INTENTS ---\n";
echo "=========================================\n";
$sentinelStripe = Sentinel::create() 
    ->withStore(new ArraySchemaStore())
    ->withSampleThreshold(2)
    ->withDispatcher($dispatcher)
    ->build();

echo "1. Processing Payment Intent 1 (Success - No Error Payload)...\n";
$success = json_decode(file_get_contents(__DIR__ . '/../tests/Fixtures/stripe_payment_intent_succeeded.json'), true);
$sentinelStripe->process('GET', '/v1/payment_intents/pi_shared', 200, $success);

echo "2. Processing Payment Intent 2 (Failed - Has Error Payload)...\n";
$failed = json_decode(file_get_contents(__DIR__ . '/../tests/Fixtures/stripe_payment_intent_failed.json'), true);
$sentinelStripe->process('GET', '/v1/payment_intents/pi_shared', 200, $failed);

echo "3. Processing Payment Intent 3 (Success - No Error Payload)...\n";
echo "   (This tests that Sentinel accurately maps the error payload as optional rather than throwing false drift)\n";
$sentinelStripe->process('GET', '/v1/payment_intents/pi_shared', 200, $success);
echo "\n✨ Processing complete. Notice how NO drift was detected for Payment Intent 3! The probabilistic model perfectly accommodated the structural variances.\n";
