<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Sentinel\Drift\DriftReporter;
use Sentinel\Middleware\SchemaWatcher;
use Sentinel\Sentinel;
use Sentinel\Store\ArraySchemaStore;

function makeSentinel(ArraySchemaStore $store, int $threshold = 1): Sentinel {
    return Sentinel::create()->withStore($store)->withSampleThreshold($threshold)->build();
}

function makeWatcher(Sentinel $sentinel, array $responses): SchemaWatcher {
    $mock    = new MockHandler($responses);
    $handler = HandlerStack::create($mock);
    $client  = new Client(['handler' => $handler]);

    $reporter = new DriftReporter(new class implements \Psr\EventDispatcher\EventDispatcherInterface {
        public function dispatch(object $event): object { return $event; }
    });

    return new SchemaWatcher($client, $sentinel, $reporter);
}

it('returns response body byte-for-byte unchanged', function () {
    $body     = json_encode(['order' => ['id' => 1, 'total' => '99.00']]);
    $store    = new ArraySchemaStore();
    $watcher  = makeWatcher(makeSentinel($store), [new Response(200, ['Content-Type' => 'application/json'], $body)]);
    $request  = new \GuzzleHttp\Psr7\Request('GET', 'https://api.example.com/orders/1');
    $response = $watcher->sendRequest($request);

    expect((string) $response->getBody())->toBe($body);
});

it('does not profile non-JSON responses', function () {
    $store   = new ArraySchemaStore();
    $watcher = makeWatcher(makeSentinel($store), [new Response(200, ['Content-Type' => 'text/html'], '<html/>')]);
    $request = new \GuzzleHttp\Psr7\Request('GET', 'https://example.com/page');
    $watcher->sendRequest($request);

    expect($store->all())->toBeEmpty();
});

it('does not profile 4xx responses', function () {
    $store   = new ArraySchemaStore();
    $watcher = makeWatcher(makeSentinel($store), [new Response(404, ['Content-Type' => 'application/json'], '{"error":"not found"}')]);
    $request = new \GuzzleHttp\Psr7\Request('GET', 'https://api.example.com/orders/9999');
    $watcher->sendRequest($request);

    expect($store->all())->toBeEmpty();
});

it('adds sample after first successful JSON response', function () {
    $store   = new ArraySchemaStore();
    $body    = json_encode(['id' => 1]);
    $watcher = makeWatcher(makeSentinel($store, 5), [new Response(200, ['Content-Type' => 'application/json'], $body)]);
    $request = new \GuzzleHttp\Psr7\Request('GET', 'https://api.example.com/items/1');
    $watcher->sendRequest($request);

    expect($store->getSamples('GET /items/{id}')->count())->toBe(1);
});
