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

// The comment above is only half right: headers_sent() is live-confirmed to
// actually be FALSE inside a Pest test body (a throwaway probe dumped it
// directly -- the same "don't trust a CLI-limitation comment without
// re-verifying" lesson applies here too), so emit()'s `! headers_sent()` branch genuinely does
// run. What's still true is that header()/headers_list() themselves are
// no-ops under CLI SAPI (confirmed live: headers_list() stays empty even
// after a real header() call) -- so *that* half is closed instead via a
// real disposable php -S server and a raw socket read of the actual HTTP
// response, mirroring HttpClientServiceTest's own established local-server
// technique.

/**
 * @return array{0: resource, 1: int} the process handle and the bound port
 */
function responseEmitterTestStartServer(string $docRoot): array
{
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $port = random_int(20_000, 60_000);
        $proc = proc_open(['php', '-S', '127.0.0.1:' . $port, '-t', $docRoot], $descriptors, $pipes);
        if (! is_resource($proc)) {
            throw new RuntimeException('failed to start local test server');
        }

        set_error_handler(static fn (): bool => true);
        try {
            for ($i = 0; $i < 100; $i++) {
                $sock = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
                if (is_resource($sock)) {
                    fclose($sock);

                    return [$proc, $port];
                }
                usleep(20_000);
            }
        } finally {
            restore_error_handler();
        }

        proc_terminate($proc);
        proc_close($proc);
    }

    throw new RuntimeException('local test server never became reachable after 5 attempts');
}

/**
 * @param resource $proc
 */
function responseEmitterTestStopServer($proc): void
{
    proc_terminate($proc);
    proc_close($proc);
}

function responseEmitterTestRawResponse(int $port, string $query): string
{
    $sock = fsockopen('127.0.0.1', $port, $errno, $errstr, 2.0);
    if (! is_resource($sock)) {
        throw new RuntimeException('failed to connect to local test server: ' . $errstr);
    }
    fwrite($sock, "GET /?{$query} HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n");
    $raw = '';
    while (! feof($sock)) {
        $raw .= fread($sock, 8192);
    }
    fclose($sock);

    return $raw;
}

function responseEmitterTestDocRoot(): string
{
    $docRoot = sys_get_temp_dir() . '/pwg-response-emitter-test-' . bin2hex(random_bytes(8));
    mkdir($docRoot, 0o777, true);
    file_put_contents(
        $docRoot . '/index.php',
        '<?php require ' . var_export(dirname(__DIR__, 3) . '/vendor/autoload.php', true) . ';'
        . '$factory = new \Nyholm\Psr7\Factory\Psr17Factory();'
        . '$mode = $_GET[\'mode\'] ?? \'basic\';'
        . 'if ($mode === \'basic\') {'
        . '  $response = $factory->createResponse(404, \'Custom Reason\')->withHeader(\'X-Piwigo-Test\', \'hello\')->withBody($factory->createStream(\'body-content\'));'
        . '} elseif ($mode === \'multivalue\') {'
        . '  $response = $factory->createResponse(200)->withHeader(\'X-Multi\', \'a\')->withAddedHeader(\'X-Multi\', \'b\')->withBody($factory->createStream(\'\'));'
        . '} else {'
        . '  $response = $factory->createResponse(200)->withHeader(\'X-Powered-By\', \'PiwigoCustom\')->withBody($factory->createStream(\'\'));'
        . '}'
        . '(new \Piwigo\Http\ResponseEmitter())->emit($response);',
    );

    return $docRoot;
}

function responseEmitterTestCleanupDocRoot(string $docRoot): void
{
    unlink($docRoot . '/index.php');
    rmdir($docRoot);
}

test('emit sends the real status code, custom reason phrase, custom header, and body over a real connection', function (): void {
    // Kills line 16's IfNegated/RemoveNot (both invert the `! headers_sent()`
    // guard -- headers_sent() is genuinely false for a fresh, single-shot
    // request, so an inverted guard would skip the header block entirely,
    // leaving the default 200 status and no X-Piwigo-Test header) and line
    // 17's RemoveFunctionCall (the status-line header() call itself -- its
    // absence leaves the SAPI's own default 200 status instead of 404).
    $docRoot = responseEmitterTestDocRoot();
    [$proc, $port] = responseEmitterTestStartServer($docRoot);

    try {
        $raw = responseEmitterTestRawResponse($port, 'mode=basic');

        expect($raw)->toContain("HTTP/1.1 404 Custom Reason\r\n")
            ->and($raw)->toContain("X-Piwigo-Test: hello\r\n")
            ->and($raw)->toEndWith('body-content');
    } finally {
        responseEmitterTestStopServer($proc);
        responseEmitterTestCleanupDocRoot($docRoot);
    }
});

test('emit sends every value of a multi-value header as its own line, replacing only on the first', function (): void {
    // Kills line 23/25's ForeachEmptyIterable (either loop skipped entirely
    // would drop X-Multi altogether) and line 26's RemoveFunctionCall (the
    // per-value header() call itself) and line 27's FalseToTrue (`$replace
    // = true` on every value instead of just the first would make the
    // SECOND header() call replace the first, leaving only "b").
    $docRoot = responseEmitterTestDocRoot();
    [$proc, $port] = responseEmitterTestStartServer($docRoot);

    try {
        $raw = responseEmitterTestRawResponse($port, 'mode=multivalue');

        expect($raw)->toContain("X-Multi: a\r\nX-Multi: b\r\n");
    } finally {
        responseEmitterTestStopServer($proc);
        responseEmitterTestCleanupDocRoot($docRoot);
    }
});

test('emit\'s first header value replaces the SAPI\'s own default header of the same name, not just appends to it', function (): void {
    // Kills line 24's TrueToFalse (`$replace = true` -> `false` on the
    // FIRST value of a header name). Live-verified this needs a header
    // name PHP's built-in server ALREADY sends unprompted -- a first
    // attempt using Content-Type (assuming its own automatic "text/html"
    // default would survive a replace=false first call) turned out to be
    // wrong: PHP suppresses that particular default the moment ANY
    // Content-Type header is sent at all, regardless of the replace flag
    // used. X-Powered-By behaves differently and does distinguish this:
    // confirmed live (hand-applying the mutation and re-running this
    // exact scenario) that replace=false leaves BOTH "X-Powered-By:
    // PHP/8.5.x" and our own value as two separate lines, while real
    // code's replace=true produces only ours.
    $docRoot = responseEmitterTestDocRoot();
    [$proc, $port] = responseEmitterTestStartServer($docRoot);

    try {
        $raw = responseEmitterTestRawResponse($port, 'mode=powered-by-override');

        expect($raw)->toContain('X-Powered-By: PiwigoCustom')
            ->and(substr_count($raw, 'X-Powered-By:'))->toBe(1);
    } finally {
        responseEmitterTestStopServer($proc);
        responseEmitterTestCleanupDocRoot($docRoot);
    }
});

/**
 * Confirmed-equivalent: line 22's TrueToFalse (the status-line header()
 * call's own `true` replace argument, changed to `false`). emit() only
 * ever sends ONE status-line header() call per request -- confirmed live
 * (hand-applied to source, re-ran the "real status code..." test above,
 * same pass either way): with nothing preceding it to replace, the
 * $replace flag has no observable effect, matching the same finding
 * already made for HtmlService::setStatusHeader() (see
 * HtmlServiceTest.php's own setStatusHeader real-server tests).
 */
