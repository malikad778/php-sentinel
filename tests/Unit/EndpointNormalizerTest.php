<?php

use Sentinel\Normalization\EndpointNormalizer;

it('normalizes numeric IDs', function () {
    $n = new EndpointNormalizer();
    expect($n->normalize('GET', 'https://shop.myshopify.com/orders/12345'))
        ->toBe('GET /orders/{id}');
});

it('normalizes UUIDs', function () {
    $n = new EndpointNormalizer();
    expect($n->normalize('GET', '/products/123e4567-e89b-12d3-a456-426614174000'))
        ->toBe('GET /products/{uuid}');
});

it('strips query parameters by default', function () {
    $n = new EndpointNormalizer();
    expect($n->normalize('GET', '/products.json?limit=250&page=2'))
        ->toBe('GET /products.json');
});

it('uppercases the HTTP method', function () {
    $n = new EndpointNormalizer();
    expect($n->normalize('post', '/orders'))
        ->toBe('POST /orders');
});

it('applies custom regex patterns', function () {
    $n = new EndpointNormalizer();
    $n->addPattern('/\/shop\/[a-z0-9-]+\.myshopify\.com/', '/shop/{shop}');
    expect($n->normalize('GET', 'https://shop/shop/my-store.myshopify.com/products'))
        ->toContain('/shop/{shop}/products');
});

it('produces same key for different numeric IDs on same endpoint', function () {
    $n = new EndpointNormalizer();
    $key1 = $n->normalize('GET', '/orders/111');
    $key2 = $n->normalize('GET', '/orders/999');
    expect($key1)->toBe($key2);
});
