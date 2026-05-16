<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Ws;

use PHPUnit\Framework\TestCase;
use Piwigo\Event\Ws\WsMethodsRegistering;
use Piwigo\Ws\WsMethodRegistrar;

/**
 * Pins the WsMethodRegistrar event subscription contract that B11
 * introduces.
 *
 * The 1413-LOC registration body itself is exercised end-to-end by the
 * existing integration suite (which spins up the full container and
 * runs `?_method_list`); this unit test just locks down the wiring so
 * a future refactor can't silently break the dispatch.
 */
final class WsMethodRegistrarSubscriptionTest extends TestCase
{
    public function testSubscribesToWsMethodsRegisteringWithCorePriority(): void
    {
        $events = WsMethodRegistrar::getSubscribedEvents();
        self::assertArrayHasKey(WsMethodsRegistering::class, $events);
        self::assertSame(['onMethodsRegistering', 100], $events[WsMethodsRegistering::class]);
    }
}
