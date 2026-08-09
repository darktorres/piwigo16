<?php

declare(strict_types=1);

use Piwigo\Http\RequestFactory;

test('fromGlobals builds a PSR-7 ServerRequest from superglobals', function (): void {
    $_SERVER['REQUEST_METHOD']  = 'GET';
    $_SERVER['REQUEST_URI']     = '/index.php?foo=bar';
    $_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
    $_SERVER['HTTP_HOST']       = 'example.test';
    $_GET['foo']                = 'bar';

    $request = RequestFactory::fromGlobals();

    expect($request->getMethod())->toBe('GET');
    expect((string) $request->getUri())->toContain('/index.php');
    expect($request->getQueryParams())->toBe(['foo' => 'bar']);
});
