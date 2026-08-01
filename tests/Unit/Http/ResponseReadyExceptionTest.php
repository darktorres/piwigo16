<?php

declare(strict_types=1);

use Nyholm\Psr7\Response;
use Piwigo\Http\ResponseReadyException;

test('carries the response it was constructed with, and a real, non-empty message', function (): void {
    // Kills line 43's RemoveMethodCall -- every other test that throws
    // ResponseReadyException only ever checks response()/getStatusCode(),
    // never getMessage(), so removing the parent::__construct() call
    // entirely (leaving PHP's own default empty-string message) was
    // otherwise unobservable.
    $response = new Response(500);
    $exception = new ResponseReadyException($response);

    expect($exception->response())->toBe($response);
    expect($exception->getMessage())->toBe(
        'A response was constructed but not yet emitted -- this exception must be caught by one of the 3 dispatch-context catch points, never allowed to reach a generic error handler.',
    );
});
