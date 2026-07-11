<?php

declare(strict_types=1);

use Nyholm\Psr7\Response;
use Piwigo\Http\ResponseEmitter;

// No assertion on headers_list(): by the time any test body runs, Pest's own
// console output has already made headers_sent() true under CLI SAPI, so
// emit()'s header() calls are guaranteed no-ops here regardless of
// correctness -- this is a real PHP CLI-testing limitation, not something
// this test can work around. The reference implementation (16.x-rewrite)
// makes the same call: no ResponseEmitterTest.php exists there either.
// The body-echo path below is the part that's reliably testable in CLI.
test('emit sends the response body', function (): void {
    $response = new Response(200, ['X-Piwigo-Test' => 'value'], 'hello world');

    ob_start();
    new ResponseEmitter()->emit($response);
    $output = ob_get_clean();

    expect($output)->toBe('hello world');
});
