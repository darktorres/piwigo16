<?php

declare(strict_types=1);

use Piwigo\Bootstrap\RedirectService;
use Piwigo\Http\ResponseReadyException;

// Workstream C3: redirectHttp() throws ResponseReadyException instead of
// calling header()/exit() directly, which is what makes it unit-testable
// for the first time -- there was never a RedirectServiceTest.php before
// this conversion (exit()-terminated methods can't be asserted on).
//
// redirectHttp() is typed `: never`, so PHPStan proves any code
// following a call to it never runs, in or out of a try block -- the
// exception is captured into a variable already declared before the try
// (never reassigned inside it under normal control flow) and asserted on
// afterwards, so every assertion sits in code PHPStan doesn't consider
// provably dead.

test('redirectHttp throws ResponseReadyException with a 302 redirect to the given URL', function (): void {
    $service = new RedirectService();
    $exception = null;
    try {
        $service->redirectHttp('http://example.test/target.php');
    } catch (ResponseReadyException $e) {
        $exception = $e;
    }

    expect($exception)->toBeInstanceOf(ResponseReadyException::class);
    $response = $exception->response();
    expect($response->getStatusCode())->toBe(302);
    expect($response->getHeaderLine('Location'))->toBe('http://example.test/target.php');
});

test('redirectHttp html_entity_decode()s the URL before redirecting', function (): void {
    $service = new RedirectService();
    $exception = null;
    try {
        $service->redirectHttp('http://example.test/target.php?a=1&amp;b=2');
    } catch (ResponseReadyException $e) {
        $exception = $e;
    }

    expect($exception)->toBeInstanceOf(ResponseReadyException::class);
    expect($exception->response()->getHeaderLine('Location'))->toBe('http://example.test/target.php?a=1&b=2');
});
