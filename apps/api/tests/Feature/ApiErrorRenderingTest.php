<?php

test('errors under /v1 render as problem+json even when the request sets no Accept header', function () {
    $response = $this->get('/v1/this-route-does-not-exist');

    $response->assertNotFound();
    expect($response->headers->get('Content-Type'))->toBe('application/problem+json');
});
