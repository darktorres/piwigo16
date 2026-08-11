<?php

declare(strict_types=1);

use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Ws\Protocol\JsonEncoder;
use Piwigo\Ws\Protocol\RestEncoder;
use Piwigo\Ws\Protocol\RestRequestHandler;
use Piwigo\Ws\Protocol\SerialPhpEncoder;
use Piwigo\Ws\Protocol\XmlRpcEncoder;
use Piwigo\Ws\WsInitializer;

/**
 * Piwigo\Ws\WsInitializer -- builds the per-request Server and
 * registers the WS default event handlers. Resolved via
 * `Kernel::container()->get()`: its own 8 constructor deps include
 * `WsDefaultMethods`/`Core` (each themselves wrapping ~10-25 further
 * domain Services), so hand-constructing that whole graph would be
 * strictly worse than trusting the real, already-tested container
 * wiring -- same rationale as `UpdatesSubControllerTest.php`'s own
 * `Kernel::container()->get()` use for a Controller/Admin SubController,
 * applied here to a Ws-layer class instead. No dedicated Integration/
 * Browser spec of its own -- `WsController` (every real WS HTTP request)
 * calls `init()` transitively.
 *
 * `init()`'s own event-handler registrations (`WsAddMethods`/
 * `WsInvokeAllowed`/`GetHistory`) are not asserted on directly here --
 * they wire `WsDefaultMethods::register()`/`WsHelper::isInvokeAllowed()`/
 * `Core::historyGet()`, each already deferred/covered on their own
 * terms elsewhere in the B4 bucket.
 */
function wsInitializerTestGet(): WsInitializer
{
    $wsInitializer = Kernel::container()->get(WsInitializer::class);
    if (! $wsInitializer instanceof WsInitializer) {
        throw new LogicException('Container returned an unexpected type for ' . WsInitializer::class);
    }

    return $wsInitializer;
}

beforeEach(function (): void {
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir()));
});

afterEach(function (): void {
    unset($_GET['format']);
    Kernel::reset();
});

test('init defaults to a REST request handler and REST response encoder when no format is requested', function (): void {
    unset($_GET['format']);

    $server = wsInitializerTestGet()
        ->init();

    expect($server->requestFormat)
        ->toBe('rest')
        ->and($server->requestHandler)
        ->toBeInstanceOf(RestRequestHandler::class)
        ->and($server->responseFormat)
        ->toBe('rest')
        ->and($server->responseEncoder)
        ->toBeInstanceOf(RestEncoder::class);
});

test('init memoizes the built Server across repeated calls on the same instance', function (): void {
    $wsInitializer = wsInitializerTestGet();

    $first = $wsInitializer->init();
    $second = $wsInitializer->init();

    expect($second)
        ->toBe($first);
});

test('init selects the response encoder matching ?format=json', function (): void {
    $_GET['format'] = 'json';

    $server = wsInitializerTestGet()
        ->init();

    expect($server->responseFormat)
        ->toBe('json')
        ->and($server->responseEncoder)
        ->toBeInstanceOf(JsonEncoder::class);
});

test('init selects the response encoder matching ?format=php', function (): void {
    $_GET['format'] = 'php';

    $server = wsInitializerTestGet()
        ->init();

    expect($server->responseFormat)
        ->toBe('php')
        ->and($server->responseEncoder)
        ->toBeInstanceOf(SerialPhpEncoder::class);
});

test('init selects the response encoder matching ?format=xmlrpc', function (): void {
    $_GET['format'] = 'xmlrpc';

    $server = wsInitializerTestGet()
        ->init();

    expect($server->responseFormat)
        ->toBe('xmlrpc')
        ->and($server->responseEncoder)
        ->toBeInstanceOf(XmlRpcEncoder::class);
});

test('init leaves the response encoder null for an unrecognized format', function (): void {
    $_GET['format'] = 'not-a-real-format';

    $server = wsInitializerTestGet()
        ->init();

    expect($server->responseFormat)
        ->toBe('not-a-real-format')
        ->and($server->responseEncoder)
        ->toBeNull();
});
