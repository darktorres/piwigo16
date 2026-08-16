<?php

declare(strict_types=1);

use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Ws\Core\GetMethodListHandler;
use Piwigo\Ws\MethodDefinition;
use Piwigo\Ws\Server;
use Piwigo\Ws\WsInitializer;

/**
 * Piwigo\Ws\Core\GetMethodListHandler -- `reflection.getMethodList`. The
 * real `Server` behind this handler is the same `WsInitializer`-memoized
 * instance every real WS request shares (`WsInitializer::init()` is safe
 * to call a second time mid-request -- see its own docblock), so this
 * primes it directly and registers 2 throwaway test methods before
 * resolving the handler from the same container, rather than dispatching
 * all 94+ real registrations via `Server::run()`.
 */
function pwgCoreGetMethodListHandlerTestServer(): Server
{
    $wsInitializer = Kernel::container()->get(WsInitializer::class);
    if (! $wsInitializer instanceof WsInitializer) {
        throw new LogicException('Container returned an unexpected type for ' . WsInitializer::class);
    }

    return $wsInitializer->init();
}

function pwgCoreGetMethodListHandlerTestSubject(): GetMethodListHandler
{
    $handler = Kernel::container()->get(GetMethodListHandler::class);
    if (! $handler instanceof GetMethodListHandler) {
        throw new LogicException('Container returned an unexpected type for ' . GetMethodListHandler::class);
    }

    return $handler;
}

beforeEach(function (): void {
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir()));
});

afterEach(function (): void {
    Kernel::reset();
});

test('lists only non-hidden methods wrapped in a methods NamedArray', function (): void {
    $server = pwgCoreGetMethodListHandlerTestServer();
    $server->register(MethodDefinition::forLegacyCallback(
        name: 'test.visible',
        callback: fn (array $params): array => [],
    ));
    $server->register(MethodDefinition::forLegacyCallback(
        name: 'test.hidden',
        callback: fn (array $params): array => [],
        hidden: true,
    ));

    $result = pwgCoreGetMethodListHandlerTestSubject()([]);

    expect($result['methods']->content)
        ->toBe(['test.visible']);
});
