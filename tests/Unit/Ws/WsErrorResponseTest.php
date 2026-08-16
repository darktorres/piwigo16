<?php

declare(strict_types=1);

use Piwigo\Ws\WsErrorResponse;

/**
 * WsErrorResponse -- a pure value object (P25 Stage 2 item 3: its
 * constructor used to mutate global response state for HTTP-range codes
 * via PresentationAccessor::htmlService()->setStatusHeader(), which is
 * why this file used to need a booted Kernel and a SetStatusHeader event
 * spy just to construct one; Server::sendResponse() now owns that
 * mapping instead -- see ServerTest.php's own boundary coverage for it).
 */
test('code() and message() return exactly what the constructor was given', function (): void {
    $error = new WsErrorResponse(404, 'Not found');

    expect($error->code())
        ->toBe(404)
        ->and($error->message())
        ->toBe('Not found');
});

test('constructing a WsErrorResponse for an HTTP-range code has no side effect', function (): void {
    // No Kernel::boot() anywhere in this file -- if the constructor still
    // reached for a container-resolved collaborator the way it used to,
    // this would fail outright rather than silently pass.
    $error = new WsErrorResponse(500, 'Server error');

    expect($error->code())
        ->toBe(500)
        ->and($error->message())
        ->toBe('Server error');
});
