<?php

declare(strict_types=1);

use Nyholm\Psr7\Request;
use Piwigo\Http\HttpClientNetworkException;
use Piwigo\Http\HttpClientSsrfException;

/**
 * Piwigo\Http\HttpClientSsrfException/HttpClientNetworkException --
 * HttpClientServiceTest already exercises both being thrown, but never
 * calls getRequest().
 */
test('HttpClientSsrfException exposes the request that triggered it', function (): void {
    $request = new Request('GET', 'https://example.test/');
    $exception = new HttpClientSsrfException('blocked by SSRF guard', $request);

    expect($exception->getRequest())
        ->toBe($request);
    expect($exception->getMessage())
        ->toBe('blocked by SSRF guard');
});

test('HttpClientNetworkException exposes the request that triggered it, with an optional previous exception', function (): void {
    $request = new Request('GET', 'https://example.test/');
    $previous = new RuntimeException('connection refused');
    $exception = new HttpClientNetworkException('transport failure', $request, $previous);

    expect($exception->getRequest())
        ->toBe($request);
    expect($exception->getMessage())
        ->toBe('transport failure');
    expect($exception->getPrevious())
        ->toBe($previous);
    // Kills line 21's DecrementInteger/IncrementInteger on the
    // hardcoded exception code (0) passed to parent::__construct().
    expect($exception->getCode())
        ->toBe(0);
});
